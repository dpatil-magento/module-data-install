<?php

/**
 * Copyright © Adobe. All rights reserved.
 */
namespace MagentoEse\DataInstall\Model\DataTypes;

use FireGento\FastSimpleImport\Model\ImporterFactory as Importer;
use MagentoEse\DataInstall\Helper\Helper;

class AdvancedPricing
{
    /** @var string  */
    const DEFAULT_WEBSITE = 'All Websites [USD]';

    /**
     *
     */
    const DEFAULT_CUSTOMER_GROUP = 'ALL GROUPS';

    /** @var Helper */
    protected $helper;

    /** @var Importer */
    protected $importer;

    /**
     * AdvancedPricing constructor.
     * @param Helper $helper
     * @param Importer $importer
     */
    public function __construct(
        Helper $helper,
        Importer $importer
    ) {
        $this->importer = $importer;

        $this->helper = $helper;
    }

    /**
     * @param array $rows
     * @param array $header
     * @param string $modulePath
     * @param array $settings
     * @return bool
     */
    public function install(array $rows, array $header, string $modulePath, array $settings)
    {
        //need to set default for tier_price_website = settings[site_code],tier_price_customer_group

        if (!empty($settings['product_validation_strategy'])) {
            $productValidationStrategy = $settings['product_validation_strategy'];
        } else {
            $productValidationStrategy =  'validation-skip-errors';
        }

        foreach ($rows as $row) {
            $productsArray[] = array_combine($header, $row);
        }
        //set default group and website if they arent included
        foreach ($productsArray as $productRow) {
            if (empty($productRow['tier_price_website'])) {
                $productRow['tier_price_website'] = self::DEFAULT_WEBSITE;
            }
            if (empty($productRow['tier_price_customer_group'])) {
                $productRow['tier_price_customer_group'] = self::DEFAULT_CUSTOMER_GROUP;
            }
            $updatedProductsArray[]=$productRow;
        }

        $this->import($updatedProductsArray, $productValidationStrategy);

        return true;
    }

    /**
     * @param $productsArray
     * @param $imgDir
     * @param $productValidationStrategy
     */
    private function import($productsArray, $productValidationStrategy)
    {
        $importerModel = $this->importer->create();
        $importerModel->setEntityCode('advanced_pricing');
        //$importerModel->setImportImagesFileDir($imgDir);
        $importerModel->setValidationStrategy($productValidationStrategy);
        if ($productValidationStrategy == 'validation-stop-on-errors') {
            $importerModel->setAllowedErrorCount(1);
        } else {
            $importerModel->setAllowedErrorCount(100);
        }
        try {
            $importerModel->processImport($productsArray);
        } catch (\Exception $e) {
            $this->helper->printMessage($e->getMessage());
        }

        $this->helper->printMessage($importerModel->getLogTrace());
        $this->helper->printMessage($importerModel->getErrorMessages());

        unset($importerModel);
    }    
}
