<?php
/**
 * ElaticSearch query model
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Smile Searchandising Suite to newer
 * versions in the future.
 *
 * This work is a fork of Johann Reinke <johann@bubblecode.net> previous module
 * available at https://github.com/jreinke/magento-elasticsearch
 *
 * @category  Smile
 * @package   Smile_ElasticSearch
 * @author    Aurelien FOUCRET <aurelien.foucret@smile.fr>
 * @copyright 2013 Smile
 * @license   Apache License Version 2.0
 */
class Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Query
    extends Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Abstract
{
    const DEFAULT_ROWS_LIMIT = 20;

    /**
     * @var string
     */
    protected $_queryType = 'default';


    /**
     * @var string
     */
    protected $_type;

    /**
     * @var array
     */
    protected $_filters = array();

    /**
     * @var array
     */
    protected $_facets = array();

    /**
     * @var array
     */
    protected $_page = array('from' => 0, 'size' => self::DEFAULT_ROWS_LIMIT);

    /**
     * @var string
     */
    protected $_fulltextQuery = '';

    /**
     * @var array
     */
    protected $_sort = array();

    /**
     * @var array
     */
    protected $_facetModelNames = array(
       'terms'      => 'smile_elasticsearch/engine_elasticsearch_query_facet_terms',
       'histogram'  => 'smile_elasticsearch/engine_elasticsearch_query_facet_histogram',
       'queryGroup' => 'smile_elasticsearch/engine_elasticsearch_query_facet_queryGroup',
    );

    /**
     * @var array
     */
    protected $_filterModelNames = array(
        'terms' => 'smile_elasticsearch/engine_elasticsearch_query_filter_terms',
        'range' => 'smile_elasticsearch/engine_elasticsearch_query_filter_range',
        'query' => 'smile_elasticsearch/engine_elasticsearch_query_filter_queryString'
    );

    /**
     * Set types of documents matched by the query.
     *
     * @param string $type Type of documents
     *
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Query
     */
    public function setType($type)
    {
        $this->_type = $type;
        return $this;
    }

    /**
     * Allow to give the query a type.
     * Can be used by observers to know if they should be applied to the query or not.
     *
     * Default query type is equal to default.
     * Layer views change query type to "product_search_layer" and "category_products_layer"
     *
     * @param string $type Type of the query.
     *
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Query
     */
    public function setQueryType($type)
    {
        $this->_queryType = $type;
        return $this;
    }

    /**
     * Return the query type.
     *
     * @return string
     */
    public function getQueryType()
    {
        return $this->_queryType;
    }

    /**
     * Get types of documents matched by the query.
     *
     * @return string
     */
    public function getType()
    {
        return $this->_type;
    }

    /**
     * Run the query against ElasticSearch.
     *
     * @return array
     */
    public function search()
    {
        $result = array();
        $query = $this->_assembleQuery();

        $eventData = new Varien_Object(array('query' => $query, 'query_type' => $this->getQueryType()));
        Mage::dispatchEvent('smile_elasticsearch_query_assembled', array('query_data' => $eventData));
        $query = $eventData->getQuery();

        $response = $this->getClient()->search($query);

        if (!isset($data['error'])) {
            $result = array(
                'total_count'  => $response['hits']['total'],
                'faceted_data' => array(),
                'docs'         => array(),
                'ids'          => array()
            );

            foreach ($response['hits']['hits'] as $doc) {
                $result['docs'][] = $doc['_source'];
                $result['ids'][] = $doc['_source']['id'];
            }

            if (isset($response['facets'])) {
                foreach ($this->_facets as $facetName => $facetModel) {
                    if ($facetModel->isGroup()) {
                        $result['faceted_data'][$facetName] = $facetModel->getItems($response['facets']);
                    } else if (isset($response['facets'][$facetName])) {
                        $result['faceted_data'][$facetName] = $facetModel->getItems($response['facets'][$facetName]);
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Set the fulltext query part of the query.
     *
     * @param string $query The fulltext query
     *
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Query
     */
    public function setFulltextQuery($query)
    {
        $this->_fulltextQuery = $query;
        return $this;
    }

    /**
     * Append a sort order.
     *
     * @param array $sortOrder Sort order definition
     *
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Query
     */
    public function addSortOrder($sortOrder)
    {
        if (array(is_array($sortOrder)) && is_array(current($sortOrder))) {
            foreach ($sortOrder as $currentSortOrder) {
                $this->addSortOrder($currentSortOrder);
            }
        } else if (is_array($sortOrder)) {
            $this->_sort[] = $sortOrder;
        }
        return $this;
    }

    /**
     * Escapes specified value.
     *
     * @param string $value Value to be escaped
     *
     * @return mixed
     *
     * @link http://lucene.apache.org/core/3_6_0/queryparsersyntax.html
     */
    protected function _escape($value)
    {
        $result = $value;
        // \ escaping has to be first, otherwise escaped later once again
        $chars = array('\\', '+', '-', '&&', '||', '!', '(', ')', '{', '}', '[', ']', '^', '"', '~', '*', '?', ':', '/');

        foreach ($chars as $char) {
            $result = str_replace($char, '\\' . $char, $result);
        }

        return $result;
    }

    /**
     * Escapes specified phrase.
     *
     * @param string $value Value to be escaped
     *
     * @return string
     */
    protected function _escapePhrase($value)
    {
        $pattern = '/("|\\\)/';
        $replace = '\\\$1';

        return preg_replace($pattern, $replace, $value);
    }

    /**
     * Phrases specified value.
     *
     * @param string $value Value to be escaped
     *
     * @return string
     */
    protected function _phrase($value)
    {
        return '"' . $this->_escapePhrase($value) . '"';
    }

    /**
     * Prepares filter query text.
     *
     * @param string $text Fulltext query
     *
     * @return mixed|string
     */
    public function prepareFilterQueryText($text)
    {
        $words = explode(' ', $text);
        if (count($words) > 1) {
            $text = $this->_phrase($text);
        } else {
            $text = $this->_escape($text);
        }

        return $text;
    }

    /**
     * Set pagination.
     *
     * @param int $currentPage Current page navigated.
     * @param int $pageSize    Size of a single page.
     *
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Query
     */
    public function setPageParams($currentPage = 0, $pageSize = self::DEFAULT_ROWS_LIMIT)
    {
        $page = ($currentPage  > 0) ? (int) $currentPage  : 1;
        $rowCount = ($pageSize > 0) ? (int) $pageSize : 1;
        $this->_page['from'] = $rowCount * ($page - 1);
        $this->_page['size'] = $rowCount;

        return $this;
    }

    /**
     * Transform the query into an ES syntax compliant array.
     *
     * @return array
     */
    protected function _assembleQuery()
    {
        $query = array('index' => $this->getAdapter()->getCurrentIndex()->getCurrentName(), 'type'  => $this->getType());
        $query['body']['query']['filtered']['query']['bool']['must'][] = $this->_prepareFulltextCondition();

        foreach ($this->_facets as $facetName => $facet) {

            $facets = $facet->getFacetQuery();

            if (!$facet->isGroup()) {
                $facets = array($facetName => $facets);
            }

            foreach ($facets as $realFacetName => $facet) {
                foreach ($this->_filters as $filterFacetName => $filters) {
                    $rawFilter = array();

                    foreach ($filters as $filter) {
                        $rawFilter[] = $filter->getFilterQuery();
                    }

                    if ($filterFacetName != $facetName && $filterFacetName != '_none_') {
                        $mustConditions = $rawFilter;
                        if (isset($facet['facet_filter']['bool']['must'])) {
                            $mustConditions = array_merge($facet['facet_filter']['bool']['must'], $rawFilter);
                        }
                        $facet['facet_filter']['bool']['must'] = $mustConditions;
                    }
                }
                $query['body']['facets'][$realFacetName] = $facet;
            }
        }

        foreach ($this->_filters as $facetName => $filters) {
            $rawFilter = array();
            foreach ($filters as $filter) {
                $rawFilter[] = $filter->getFilterQuery();
            }
            if ($facetName == '_none_') {
                if (!isset($query['body']['query']['filtered']['filter']['bool']['must'])) {
                    $query['body']['query']['filtered']['filter']['bool']['must'] = array();
                }
                $mustConditions = array_merge($query['body']['query']['filtered']['filter']['bool']['must'], $rawFilter);
                $query['body']['query']['filtered']['filter']['bool']['must'] = $mustConditions;
            } else {
                if (!isset($query['body']['filter']['bool']['must'])) {
                    $query['body']['filter']['bool']['must'] = array();
                }
                $query['body']['filter']['bool']['must'] = array_merge($query['body']['filter']['bool']['must'], $rawFilter);
            }
        }
        // Patch : score not computed when using another sort order than score
        //         as primary sort order
        $query['body']['track_scores'] = true;
        $query['body']['sort'] = $this->_prepareSortCondition();
        $query['body'] = array_merge($query['body'], $this->_page);

        return $query;
    }

    /**
     * Build the sort part of the query.
     *
     * @return array
     */
    protected function _prepareSortCondition()
    {
        $result = array();
        $hasRelevance = false;

        foreach ($this->_sort as $sort) {
            $_sort = each($sort);
            $sortField = $_sort['key'];
            $sortType = $_sort['value'];
            if ($sortField == 'relevance') {
                $sortField = '_score';
                // Score has to be reversed
                $hasRelevance = true;
            } elseif ($sortField == 'position') {
                $category = Mage::registry('current_category');
                if ($category && $category->getProductCount() > 0) {
                    $sortField = 'position_category_' . Mage::registry('current_category')->getId();
                } else {
                    $sortField = '_score';
                    $sortType = $sortType == 'asc' ? 'desc' : 'asc';
                }
            } elseif ($sortField == 'price') {
                $websiteId = Mage::app()->getStore()->getWebsiteId();
                $customerGroupId = Mage::getSingleton('customer/session')->getCustomerGroupId();
                $sortField = 'price_'. $customerGroupId .'_'. $websiteId;
            } else {
                $sortField = $this->_getHelper()->getSortableAttributeFieldName($sortField);
            }
            $result[] = array($sortField => trim(strtolower($sortType)));
        }

        if (!$hasRelevance) {
            // Append relevance has last field if not yet present
            // Allow rescoring methods to be applied
            $result[] = array('_score' => 'desc');
        }

        return $result;
    }

    /**
     * Encode a text to be used into a query.
     *
     * @param string $text Text to be encoded
     *
     * @return string
     */
    protected function _prepareQueryText($text)
    {
        $words = explode(' ', $text);
        if (count($words) > 1) {
            foreach ($words as $key => &$val) {
                if (!empty($val)) {
                    $val = $this->_escape($val);
                } else {
                    unset($words[$key]);
                }
            }
            $text = '(' . implode(' ', $words) . ')';
        } else {
            $text = $this->_escape($text);
        }

        return $text;
    }

    /**
     * Build the fulltext query condition for the query.
     *
     * @return array
     */
    protected function _prepareFulltextCondition()
    {
        $query = array('match_all' => array());

        if ($this->_fulltextQuery) {
            $query = array('dis_max' => array('queries' => array()));
            $searchFields = $this->getSearchFields();

            $query['dis_max'] =  array('tie_breaker' => 0);
            $query['dis_max']['queries'][] = array(
                'multi_match' => array(
                    'query'       => $this->prepareFilterQueryText($this->_fulltextQuery),
                    'fields'      => $searchFields,
                    'type'        => 'best_fields',
                    "tie_breaker" => 0.9
                )
            );

            if ((bool) $this->getConfig('enable_fuzzy_query')) {
                $fuzzyQuery = array(
                    'fields'          => $this->getSearchFields(),
                    'like_text'       => $this->_fulltextQuery,
                    'min_similarity'  => min(0.99, max(0, (float) $this->getConfig('fuzzy_min_similarity'))),
                    'prefix_length'   => (int) $this->getConfig('fuzzy_prefix_length'),
                    'max_query_terms' => (int) $this->getConfig('fuzzy_max_query_terms'),
                    'boost'           => (float) $this->getConfig('fuzzy_query_boost'),
                    'ignore_tf'       => true
                );
                $query['dis_max']['queries'][] = array('fuzzy_like_this' => $fuzzyQuery);
            }
        }

        return $query;
    }

    /**
     * Retrieves searchable fields according to text query.
     *
     * @return array
     */
    public function getSearchFields()
    {
        $properties = $this->getAdapter()->getCurrentIndex()->getProperties();

        $fields = array();
        foreach ($properties as $key => $property) {
            if ($property['type'] == 'date' || ($property['type'] == 'multi_field' && $property['fields'][$key]['type'] == 'date')) {
                continue;
            }

            if (!is_bool($this->_fulltextQuery) && ($property['type'] == 'boolean' ||
                ($property['type'] == 'multi_field' && $property['fields'][$key]['type'] == 'boolean'))
               ) {
                continue;
            }
            if (!is_integer($this->_fulltextQuery) && ($property['type'] == 'integer' ||
                ($property['type'] == 'multi_field' && $property['fields'][$key]['type'] == 'integer'))
               ) {
                continue;
            }
            if (!is_double($this->_fulltextQuery) && ($property['type'] == 'double' ||
                ($property['type'] == 'multi_field' && $property['fields'][$key]['type'] == 'double'))
               ) {
                continue;
            }

            if ($property['type'] == 'multi_field') {
                foreach ($property['fields'] as $field => $fieldProperties) {
                    if (strpos($field, 'edge_ngram') !== 0 && strpos($field, 'suggest') !==0 ) {

                        if (isset($fieldProperties['boost'])) {
                            $field = $field . '^' . $fieldProperties['boost'];
                        }

                        $fields[] = $key . '.' . $field;
                    }
                }
            } elseif (0 !== strpos($key, 'sort_by_')) {
                $fields[] = $key;
            }
        }

        if ($this->_getHelper()->shouldSearchOnOptions()) {
            // Search on options labels too
            $fields[] = '_options';
        }

        return $fields;
    }

    /**
     * Add a filter to the query.
     *
     * @param string $modelName Name of the model to be used to create the filter.
     * @param array  $options   Options to be passed to the filter constructor.
     * @param string $facetName Associate the filter to a facet. The filter will not be applied to the facet.
     *
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Query
     */
    public function addFilter($modelName, $options = array(), $facetName = '_none_')
    {
        $modelName = $this->_getFilterModelName($modelName);

        if (!isset($this->_filters)) {
            $this->_filters[$facetName] = array();
        }

        $filter = Mage::getResourceModel($modelName, $options);
        if ($filter) {
            $filter->setQuery($this);
            $this->_filters[$facetName][] = $filter;
        }

        return $this;
    }

    /**
     * Add a facet to the query.
     *
     * @param string $name      Name of the facet.
     * @param string $modelName Name of the model to be used to create the facet.
     * @param array  $options   Options to be passed to the facet constructor.
     *
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Query
     */
    public function addFacet($name, $modelName, $options = array())
    {
        $modelName = $this->_getFacetModelName($modelName);

        $facet = Mage::getResourceModel($modelName, $options);

        if ($facet) {
            $facet->setQuery($this);
            $this->_facets[$name] = $facet;
        }

        return $this;
    }

    /**
     * Try to convert the model name from short name (eg."terms")
     * to the model name (eg. "smile_elasticsearch/engine_elasticsearch_query_facet_terms").
     *
     * If no match is found, return the model name unchanged.
     *
     * @param string $modelName Shortname to be converted.
     *
     * @return string
     */
    protected function _getFacetModelName($modelName)
    {
        if (isset($this->_facetModelNames[$modelName])) {
            $modelName = $this->_facetModelNames[$modelName];
        }
        return $modelName;
    }

    /**
     * Try to convert the model name from short name (eg."terms")
     * to the model name (eg. "smile_elasticsearch/engine_elasticsearch_query_filter_terms").
     *
     * If no match is found, return the model name unchanged.
     *
     * @param string $modelName Shortname to be converted.
     *
     * @return string
     */
    protected function _getFilterModelName($modelName)
    {
        if (isset($this->_filterModelNames[$modelName])) {
            $modelName = $this->_filterModelNames[$modelName];
        }
        return $modelName;
    }

}