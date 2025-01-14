<?php
/**
 * Copyright © Adobe, Inc. All rights reserved.
 */
namespace MagentoEse\DataInstall\Model\DataTypes;

use Magento\SharedCatalog\Api\CompanyManagementInterface;
use Magento\SharedCatalog\Api\Data\SharedCatalogInterfaceFactory;
use Magento\SharedCatalog\Api\Data\SharedCatalogInterface;
use Magento\SharedCatalog\Api\SharedCatalogRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use MagentoEse\DataInstall\Helper\Helper;

class SharedCatalogs
{

    /** @var SharedCatalogInterface */
    protected $sharedCatalogInterfaceFactory;

    /** @var SharedCatalogRepositoryInterface */
    protected $sharedCatalogRepository;

    /** @var CustomerGroups */
    protected $customerGroups;

    /** @var CompanyManagementInterface */
    protected $companyManagementInterface;

    /** @var Companies */
    protected $companies;

    /** @var SearchCriteriaBuilder */
    protected $searchCriteriaBuilder;

    /** @var Helper */
    protected $helper;

    /**
     * SharedCatalogs constructor.
     * @param Helper $helper
     * @param SharedCatalogInterfaceFactory $sharedCatalogInterface
     * @param SharedCatalogRepositoryInterface $sharedCatalogRepositoryInterface
     * @param CustomerGroups $customerGroups
     * @param CompanyManagementInterface $companyManagementInterface
     * @param Companies $companies
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        Helper $helper,
        SharedCatalogInterfaceFactory $sharedCatalogInterface,
        SharedCatalogRepositoryInterface $sharedCatalogRepositoryInterface,
        CustomerGroups $customerGroups,
        CompanyManagementInterface $companyManagementInterface,
        Companies $companies,
        SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
        $this->helper = $helper;
        $this->sharedCatalogInterfaceFactory = $sharedCatalogInterface;
        $this->sharedCatalogRepository = $sharedCatalogRepositoryInterface;
        $this->customerGroups = $customerGroups;
        $this->companyManagementInterface = $companyManagementInterface;
        $this->companies = $companies;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
    }

    /**
     * @param array $row
     * @param array $settings
     * @return bool
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function install(array $row, array $settings)
    {
        //required - name, companies is optional, but set column if it doesnt exist
        if (empty($row['name'])) {
            $this->helper->printMessage("name is required in b2b_shared_catalogs.csv, row skipped", "warning");
                return true;
        }

        if (empty($row['companies'])) {
            $row['companies'] = '';
        }
        //check for existing shared catalog to update
        /** @var SharedCatalogInterface $sharedCatalog */
        $sharedCatalog = $this->getSharedCatalogByName($row['name']);

        if (!$sharedCatalog) {
            //create customer group
            $this->customerGroups->install(['name'=>$row['name']]);
            $sharedCatalog = $this->sharedCatalogInterfaceFactory->create();
        }

        $sharedCatalog->setName($row['name']);
        $sharedCatalog->setCustomerGroupId($this->customerGroups->getCustomerGroupId($row['name']));
        $sharedCatalog->setCreatedBy(1);
        $sharedCatalog->setTaxClassId(3);
        if (!empty($row['description'])) {
            $sharedCatalog->setDescription($row['description']);
        }
        if (empty($row['type']) || $row['type']=='Custom') {
            $sharedCatalog->setType(SharedCatalogInterface::TYPE_CUSTOM);
        } else {
            $sharedCatalog->setType(SharedCatalogInterface::TYPE_PUBLIC);
        }
        $this->sharedCatalogRepository->save($sharedCatalog);

        //assign catalog to company
        $r = $sharedCatalog->getId();
        //get all compainies and return to array
        $companiesData = explode(',', $row['companies']);
        $companiesToAssign = [];
        foreach ($companiesData as $companyData) {
            $company = $this->companies->getCompanyByName($companyData);
            if ($company) {
                $companiesToAssign[]=$company;
            }
        }
        if (count($companiesToAssign)>0) {
            $this->companyManagementInterface->assignCompanies($sharedCatalog->getId(), $companiesToAssign);
        }

        return true;
    }

    /**
     * @param $sharedCatalogName
     * @return SharedCatalogInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function getSharedCatalogByName($sharedCatalogName)
    {
        $catalogSearch = $this->searchCriteriaBuilder
        ->addFilter(
            SharedCatalogInterface::NAME,
            $sharedCatalogName,
            'eq'
        )->create()->setPageSize(1)->setCurrentPage(1);
        $catalogList = $this->sharedCatalogRepository->getList($catalogSearch);
        return current($catalogList->getItems());
    }
}
