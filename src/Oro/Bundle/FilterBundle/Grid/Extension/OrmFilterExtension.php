<?php

namespace Oro\Bundle\FilterBundle\Grid\Extension;

use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Translation\TranslatorInterface;

use Oro\Bundle\EntityBundle\ORM\Registry;

use Oro\Bundle\DataGridBundle\Datagrid\Common\MetadataObject;
use Oro\Bundle\DataGridBundle\Datagrid\Common\DatagridConfiguration;
use Oro\Bundle\DataGridBundle\Datasource\DatasourceInterface;
use Oro\Bundle\DataGridBundle\Datasource\Orm\OrmDatasource;
use Oro\Bundle\DataGridBundle\Extension\AbstractExtension;
use Oro\Bundle\DataGridBundle\Extension\Formatter\Configuration as FormatterConfiguration;
use Oro\Bundle\DataGridBundle\Extension\Formatter\Property\PropertyInterface;
use Oro\Bundle\DataGridBundle\Datagrid\ParameterBag;
use Oro\Bundle\DataGridBundle\Extension\Pager\PagerInterface;
use Oro\Bundle\DataGridBundle\Extension\Sorter\OrmSorterExtension;
use Oro\Bundle\DataGridBundle\Entity\Repository\GridViewRepository;
use Oro\Bundle\FilterBundle\Filter\FilterUtility;
use Oro\Bundle\FilterBundle\Filter\FilterInterface;
use Oro\Bundle\FilterBundle\Datasource\Orm\OrmFilterDatasourceAdapter;
use Oro\Bundle\SecurityBundle\ORM\Walker\AclHelper;
use Oro\Bundle\SecurityBundle\SecurityFacade;

class OrmFilterExtension extends AbstractExtension
{
    /**
     * Query param
     */
    const FILTER_ROOT_PARAM = '_filter';
    const MINIFIED_FILTER_PARAM = 'f';

    /** @var FilterInterface[] */
    protected $filters = [];

    /** @var TranslatorInterface */
    protected $translator;

    /** @var Registry */
    protected $registry;

    /** @var SecurityFacade */
    protected $securityFacade;

    /** @var AclHelper */
    protected $aclHelper;

    /**
     * @param TranslatorInterface $translator
     * @param Registry            $registry
     * @param SecurityFacade      $securityFacade
     * @param AclHelper           $aclHelper
     */
    public function __construct(
        TranslatorInterface $translator,
        Registry $registry,
        SecurityFacade $securityFacade,
        AclHelper $aclHelper
    ) {
        $this->translator     = $translator;
        $this->registry       = $registry;
        $this->securityFacade = $securityFacade;
        $this->aclHelper      = $aclHelper;
    }

    /**
     * {@inheritDoc}
     */
    public function isApplicable(DatagridConfiguration $config)
    {
        $filters = $config->offsetGetByPath(Configuration::COLUMNS_PATH);

        if ($filters === null) {
            return false;
        }

        return $config->getDatasourceType() == OrmDatasource::TYPE;
    }

    /**
     * {@inheritDoc}
     */
    public function processConfigs(DatagridConfiguration $config)
    {
        $filters = $config->offsetGetByPath(Configuration::FILTERS_PATH);
        // validate extension configuration and pass default values back to config
        $filtersNormalized = $this->validateConfiguration(
            new Configuration(array_keys($this->filters)),
            ['filters' => $filters]
        );
        // replace config values by normalized, extra keys passed directly
        $config->offsetSetByPath(
            Configuration::FILTERS_PATH,
            array_replace_recursive($filters, $filtersNormalized)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function visitDatasource(DatagridConfiguration $config, DatasourceInterface $datasource)
    {
        $filters = $this->getFiltersToApply($config);
        $values  = $this->getValuesToApply($config);
        /** @var OrmDatasource $datasource */
        $datasourceAdapter = new OrmFilterDatasourceAdapter($datasource->getQueryBuilder());

        foreach ($filters as $filter) {
            $value = isset($values[$filter->getName()]) ? $values[$filter->getName()] : false;

            if ($value !== false) {
                $form = $filter->getForm();
                if (!$form->isSubmitted()) {
                    $form->submit($value);
                }

                if ($form->isValid()) {
                    $filter->apply($datasourceAdapter, $form->getData());
                }
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function visitMetadata(DatagridConfiguration $config, MetadataObject $data)
    {
        $currentUser = $this->getCurrentUser();
        if (!$currentUser) {
            return;
        }

        $gridName  = $config->getName();
        $gridViews = $this->getGridViewRepository()->findGridViews($this->aclHelper, $currentUser, $gridName);

        $currentState = $data->offsetGet('state');

        /** Get columns data from grid view */
        $currentGridView = null;

        if (isset($currentState['gridView'])) {
            foreach ($gridViews as $gridView) {
                if ((int)$currentState['gridView'] === $gridView->getId()) {
                    $currentGridView = $gridView;
                    break;
                }
            }
        }
        $filtersState = $data->offsetGetByPath('[state][filters]', []);

        $initialFiltersState = $filtersState;
        $filtersMetaData     = [];

        if ($currentGridView) {
            $filtersState = array_merge($currentGridView->getFiltersData(), $filtersState);
        }

        $filters       = $this->getFiltersToApply($config);
        $values        = $this->getValuesToApply($config);
        $initialValues = $this->getValuesToApply($config, false);
        $lazy          = $data->offsetGetOr(MetadataObject::LAZY_KEY, true);

        foreach ($filters as $filter) {
            if (!$lazy) {
                $filter->resolveOptions();
            }
            $value        = $this->getFilterValue($values, $filter->getName());
            $initialValue = $this->getFilterValue($initialValues, $filter->getName());

            $filtersState        = $this->updateFiltersState($filter, $value, $filtersState);
            $initialFiltersState = $this->updateFiltersState($filter, $initialValue, $initialFiltersState);

            $metadata          = $filter->getMetadata();
            $filtersMetaData[] = array_merge(
                $metadata,
                [
                    'label' => $metadata[FilterUtility::TRANSLATABLE_KEY]
                        ? $this->translator->trans($metadata['label'])
                        : $metadata['label']
                ]
            );

        }

        $data
            ->offsetAddToArray('initialState', ['filters' => $initialFiltersState])
            ->offsetAddToArray('state', ['filters' => $filtersState])
            ->offsetAddToArray('filters', $filtersMetaData)
            ->offsetAddToArray(MetadataObject::REQUIRED_MODULES_KEY, ['orofilter/js/datafilter-builder']);
    }

    /**
     * @param FilterInterface $filter
     * @param mixed           $value
     * @param array           $state
     *
     * @return array
     */
    protected function updateFiltersState(FilterInterface $filter, $value, array $state)
    {
        if ($value !== false) {
            $form = $filter->getForm();
            if (!$form->isSubmitted()) {
                $form->submit($value);
            }

            if ($form->isValid()) {
                $state[$filter->getName()] = $value;
            }
        }

        return $state;
    }

    /**
     * Add filter to array of available filters
     *
     * @param string          $name
     * @param FilterInterface $filter
     *
     * @return $this
     */
    public function addFilter($name, FilterInterface $filter)
    {
        $this->filters[$name] = $filter;

        return $this;
    }

    /**
     * @param ParameterBag $parameters
     */
    public function setParameters(ParameterBag $parameters)
    {
        if ($parameters->has(ParameterBag::MINIFIED_PARAMETERS)) {
            $minifiedParameters = $parameters->get(ParameterBag::MINIFIED_PARAMETERS);
            $filters            = [];

            if (array_key_exists(self::MINIFIED_FILTER_PARAM, $minifiedParameters)) {
                $filters = $minifiedParameters[self::MINIFIED_FILTER_PARAM];
            }

            $parameters->set(self::FILTER_ROOT_PARAM, $filters);
        }

        parent::setParameters($parameters);
    }

    /**
     * Prepare filters array
     *
     * @param DatagridConfiguration $config
     *
     * @return FilterInterface[]
     */
    protected function getFiltersToApply(DatagridConfiguration $config)
    {
        $filters       = [];
        $filtersConfig = $config->offsetGetByPath(Configuration::COLUMNS_PATH);

        foreach ($filtersConfig as $name => $definition) {
            if (isset($definition[PropertyInterface::DISABLED_KEY])
                && $definition[PropertyInterface::DISABLED_KEY]
            ) {
                // skip disabled filter
                continue;
            }

            // if label not set, try to suggest it from column with the same name
            if (!isset($definition['label'])) {
                $definition['label'] = $config->offsetGetByPath(
                    sprintf('[%s][%s][label]', FormatterConfiguration::COLUMNS_KEY, $name)
                );
            }
            $filters[] = $this->getFilterObject($name, $definition);
        }

        return $filters;
    }

    /**
     * Takes param from request and merge with default filters
     *
     * @param DatagridConfiguration $config
     * @param bool                  $readParameters
     *
     * @return array
     */
    protected function getValuesToApply(DatagridConfiguration $config, $readParameters = true)
    {
        $defaultFilters = $config->offsetGetByPath(Configuration::DEFAULT_FILTERS_PATH, []);

        if (!$readParameters) {
            return $defaultFilters;
        }

        $intersectKeys = array_intersect(
            $this->getParameters()->keys(),
            [
                OrmSorterExtension::SORTERS_ROOT_PARAM,
                PagerInterface::PAGER_ROOT_PARAM,
                ParameterBag::ADDITIONAL_PARAMETERS,
                ParameterBag::MINIFIED_PARAMETERS,
                self::FILTER_ROOT_PARAM,
                self::MINIFIED_FILTER_PARAM
            ]
        );

        $gridHasInitialState = empty($intersectKeys);

        if ($gridHasInitialState) {
            return $defaultFilters;
        }

        return $this->getParameters()->get(self::FILTER_ROOT_PARAM, []);
    }

    /**
     * Returns prepared filter object
     *
     * @param string $name
     * @param array  $config
     *
     * @return FilterInterface
     */
    protected function getFilterObject($name, array $config)
    {
        $type = $config[FilterUtility::TYPE_KEY];

        $filter = $this->filters[$type];
        $filter->init($name, $config);

        return clone $filter;
    }

    /**
     * @param array       $values
     * @param string      $key
     * @param mixed|false $default
     *
     * @return mixed
     */
    protected function getFilterValue(array $values, $key, $default = false)
    {
        return isset($values[$key]) ? $values[$key] : $default;
    }


    /**
     * @return GridViewRepository
     */
    protected function getGridViewRepository()
    {
        return $this->registry->getRepository('OroDataGridBundle:GridView');
    }

    /**
     * @return UserInterface
     */
    protected function getCurrentUser()
    {
        $user = $this->securityFacade->getLoggedUser();
        if ($user instanceof UserInterface) {
            return $user;
        }

        return null;
    }

//    /**
//     * @param $filters
//     * @param $values
//     * @param $datasourceAdapter
//     */
//    protected function buildDefaultFilterData($filters, $values, $datasourceAdapter)
//    {
//        foreach ($filters as $filter) {
//            $value = isset($values[$filter->getName()]) ? $values[$filter->getName()] : false;
//
//            if ($value !== false) {
//                $form = $filter->getForm();
//                if (!$form->isSubmitted()) {
//                    $form->submit($value);
//                }
//
//                if ($form->isValid()) {
//                    $data = $form->getData();
//                    if (isset($value['value']['start'])) {
//                        $data['value']['start_original'] = $value['value']['start'];
//                    }
//                    if (isset($value['value']['end'])) {
//                        $data['value']['end_original'] = $value['value']['end'];
//                    }
//                    $filter->apply($datasourceAdapter, $data);
//                }
//            }
//        }
//    }
}
