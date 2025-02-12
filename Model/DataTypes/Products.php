<?php

/**
 * Copyright © Adobe. All rights reserved.
 */
namespace MagentoEse\DataInstall\Model\DataTypes;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\State;
use FireGento\FastSimpleImport\Model\ImporterFactory as Importer;
use MagentoEse\DataInstall\Helper\Helper;
use Magento\Framework\App\Area as AppArea;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;

class Products
{
    /** @var Helper */
    protected $helper;

    const DEFAULT_IMAGE_PATH = '/media/catalog/product';
    const APP_DEFAULT_IMAGE_PATH = 'var';
    //TODO: flexibility for other than default category

    /** @var Stores */
    protected $stores;

    /** @var ProductRepositoryInterface */
    protected $productRepository;

    /** @var SearchCriteriaBuilder */
    protected $searchCriteriaBuilder;

    /** @var Importer */
    protected $importer;

    /** @var State */
    protected $appState;

    /** @var ReadInterface  */
    protected $directoryRead;

    /** @var Filesystem */
    protected $fileSystem;

    /**
     * Products constructor.
     * @param Helper $helper
     * @param Stores $stores
     * @param ProductRepositoryInterface $productRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param Importer $importer
     * @param State $appState
     */
    public function __construct(
        Helper $helper,
        Stores $stores,
        ProductRepositoryInterface $productRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        Importer $importer,
        State $appState,
        DirectoryList $directoryList,
        Filesystem $fileSystem
    ) {
        $this->stores = $stores;
        $this->productRepository = $productRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->importer = $importer;
        $this->appState = $appState;
        $this->helper = $helper;
        $this->directoryRead = $fileSystem->getDirectoryRead(DirectoryList::ROOT);
    }

    /**
     * @param array $rows
     * @param array $header
     * @param string $modulePath
     * @param array $settings
     */
    public function install(array $rows, array $header, string $modulePath, array $settings)
    {
        if (!empty($settings['product_image_import_directory'])) {
            $imgDir = $settings['product_image_import_directory'];
        } else {
            $imgDir = $modulePath . self::DEFAULT_IMAGE_PATH;
        }
        //check to see if the image directory exists.  If not, set it to safe default
        //this will catch the case of updating products, but not needing to include image files
        if (!$this->directoryRead->isDirectory($imgDir)) {
            $this->helper->printMessage(
                "The directory or product images ".$imgDir." does not exist. ".
                "This may cause an issue with your product import if you are expecting to include product images",
                "warning"
            );
            $imgDir = self::APP_DEFAULT_IMAGE_PATH;
        }

        if (!empty($settings['restrict_products_from_views'])) {
            $restrictProductsFromViews = $settings['restrict_products_from_views'];
        } else {
            $restrictProductsFromViews =  'N';
        }

        if (!empty($settings['product_validation_strategy'])) {
            $productValidationStrategy = $settings['product_validation_strategy'];
        } else {
            $productValidationStrategy =  'validation-skip-errors';
        }

        foreach ($rows as $row) {
            $productsArray[] = array_combine($header, $row);
        }

        /// create array to restrict existing products from other store views
        if ($restrictProductsFromViews=='Y') {
            ///get all products that are not in my view not in my data file
            //restricts from incoming store
            $restrictExistingProducts = $this->restrictExistingProducts($productsArray, $settings['store_view_code']);

            //Restrict new (not updated) products to views that arent in my store
            $restrictNewProducts = $this->restrictNewProductsFromOtherStoreViews(
                $productsArray,
                $settings['store_view_code']
            );
        }
        $productsArray = $this->replaceBaseWebsiteCodes($productsArray, $settings);
        $this->helper->printMessage("Importing products", "info");
        
        $this->import($productsArray, $imgDir, $productValidationStrategy);

        /// Restrict products from other stores
        if ($restrictProductsFromViews=='Y') {
            $this->helper->printMessage("Restricting products from other store views", "info");

            if (count($restrictExistingProducts) > 0) {
                 $this->helper->printMessage("Restricting ".count($restrictExistingProducts).
                 " products from new store view", "info");
                $this->import($restrictExistingProducts, $imgDir, $productValidationStrategy);
            }

            if (count($restrictNewProducts) > 0) {
                $this->helper->printMessage("Restricting ".count($restrictNewProducts).
                " new products from existing store views", "info");
                $this->import($restrictNewProducts, $imgDir, $productValidationStrategy);
            }
        }
    }

    /**
     * @param $restrictProducts
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\StateException
     */
    private function updateProductVisibility($restrictProducts)
    {
        foreach ($restrictProducts as $restrictProduct) {
            $product = $this->productRepository->get($restrictProduct['sku']);
            $product->setStoreId($this->stores->getViewId($restrictProduct['store_view_code']));
            $product->setVisibility($restrictProduct['visibility']);
            $this->productRepository->save($product);
        }
    }

    /**
     * @param $productsArray
     * @param $imgDir
     * @param $productValidationStrategy
     */
    private function import($productsArray, $imgDir, $productValidationStrategy)
    {
        $importerModel = $this->importer->create();
        $importerModel->setImportImagesFileDir($imgDir);
        $importerModel->setValidationStrategy($productValidationStrategy);
        if ($productValidationStrategy == 'validation-stop-on-errors') {
            $importerModel->setAllowedErrorCount(1);
        } else {
            $importerModel->setAllowedErrorCount(100);
        }
        try {
            $this->appState->emulateAreaCode(
                AppArea::AREA_ADMINHTML,
                [$importerModel, 'processImport'],
                [$productsArray]
            );
        } catch (\Exception $e) {
            $this->helper->printMessage($e->getMessage());
        }

        $this->helper->printMessage($importerModel->getLogTrace());
        $this->helper->printMessage($importerModel->getErrorMessages());

        unset($importerModel);
    }

    /**
     * @param array $products
     * @return array
     */
    private function restrictExistingProducts(array $products, $storeViewCode)
    {
        $allProductSkus = $this->productDataToSkus($this->getAllProducts());
        $productsToAdd = $this->productDataToSkus($products);
        //$productsToAdd = $this->getUniqueNewProductSkus($products,$allProductSkus);
        $products = array_diff($allProductSkus, $productsToAdd);
        $newProductArray = [];
        foreach ($products as $product) {
            $newProductArray[] = ['sku'=>$product,'store_view_code'=>$storeViewCode,
            'visibility'=>'Not Visible Individually'];
        }
        return $newProductArray;
    }

    /**
     * @param array $newProducts
     * @param $storeViewCode
     * @return array
     */
    private function restrictNewProductsFromOtherStoreViews(array $newProducts, $storeViewCode)
    {

        /////loop over all products, if that sku isn in the products array then flag it
        //get all product skus
        $allProductSkus = $this->productDataToSkus($this->getAllProducts());
        $restrictedProducts = [];
        $allStoreCodes = $this->stores->getViewCodesFromOtherStores($storeViewCode);
        $uniqueNewProductSkus = $this->getUniqueNewProductSkus($newProducts, $allProductSkus);

        //$allStoreCodes = $this->stores->getAllViews();
        foreach ($uniqueNewProductSkus as $product) {
                //add restrictive line for each
            foreach ($allStoreCodes as $storeCode) {
                if ($storeCode != $storeViewCode) {
                    $restrictedProducts[] = ['sku'=>$product,'store_view_code'=>$storeCode,
                    'visibility'=>'Not Visible Individually'];
                }
            }
        }

        return $restrictedProducts;
    }

    /**
     * @param array $newProducts
     * @param array $allProductSkus
     * @return array
     */
    private function getUniqueNewProductSkus(array $newProducts, array $allProductSkus)
    {
        $newSkus = $this->productDataToSkus($newProducts);
        return array_diff($newSkus, $allProductSkus);
    }

    /**
     * @param $products
     * @return array
     */
    private function productDataToSkus($products)
    {
        $skus = [];
        foreach ($products as $product) {
            $skus[]=$product['sku'];
        }
        return $skus;
    }

    /**
     * @return ProductInterface[]
     */
    private function getAllProducts()
    {
        $search = $this->searchCriteriaBuilder
        ->addFilter(ProductInterface::SKU, '', 'neq')
        ->create();
        $productCollection = $this->productRepository->getList($search)->getItems();

        return $productCollection;
    }

    /**
     * @return ProductInterface[]
     */
    private function getVisibleProducts()
    {
        $search = $this->searchCriteriaBuilder
        ->addFilter(ProductInterface::VISIBILITY, '4', 'eq')
        ->create();
        $productCollection = $this->productRepository->getList($search)->getItems();

        return $productCollection;
    }

    /**
     * @return array
     */
    private function getVisibleProductSkus()
    {
        $productSkus = [];
        $productCollection = $this->getVisibleProducts();
        foreach ($productCollection as $product) {
            $productSkus[] = $product->getSku();
        }

        return $productSkus;
    }

    /**
     * @param array $products
     * @param array $settings
     * @return array
     */
    private function addSettingsToImportFile($products, $settings)
    {
        $i=0;
        foreach ($products as $product) {
            //store_view_code, product_websites
            if (empty($product['store_view_code']) || $product['store_view_code']=='') {
                $product['store_view_code'] = $settings['store_view_code'];
            }
            if (empty($product['product_websites']) || $product['product_websites']=='') {
                $product['product_websites'] = $settings['site_code'];
            }
            $products[$i] = $product;
            $i++;
        }
        return $products;
    }
     
    /**
     * @param array $products
     * @param array $settings
     * @return array
     */
    private function replaceBaseWebsiteCodes($products, $settings)
    {
        $i=0;
        foreach ($products as $product) {
            //product_websites
            if (!empty($product['product_websites'])) {
                ///value may be a comma delimited list e.g. notbase,test
                $websiteArray = explode(",", $product['product_websites']);
                // phpcs:ignore Magento2.PHP.ReturnValueCheck.ImproperValueTesting
                if (is_int(array_search('base', $websiteArray))) {
                    // phpcs:ignore Magento2.PHP.ReturnValueCheck.ImproperValueTesting
                    $websiteArray[array_search('base', $websiteArray)]=$this->stores->replaceBaseWebsiteCode('base');
                    $product['product_websites'] = implode(",", $websiteArray);
                }
            }
            
            $products[$i] = $product;
            $i++;
        }
        return $products;
    }
}
