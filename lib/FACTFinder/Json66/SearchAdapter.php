<?php
/**
 * search adapter using the json interface. expects a json formated string from the dataprovider
 */
class FACTFinder_Json66_SearchAdapter extends FACTFinder_Default_SearchAdapter
{
    protected $status = null;
    protected $isArticleNumberSearch;
    private $jsonData;

    /**
     * init
     */
    protected function init()
    {
        $this->log->info("Initializing new search adapter.");
        $this->getDataProvider()->setParam('format', 'json');
        $this->getDataProvider()->setType('Search.ff');
    }
    
    /**
     * try to parse data as json
     *
     * @throws Exception of data is no valid JSON
     * @return stdClass
     */
    protected function getData()
    {
        if($this->jsonData === null)
        {
            $this->jsonData = json_decode(parent::getData(), true); // the second parameter turns JSON-objects into associative arrays which makes extracting the record fields easier
            if ($this->jsonData === null)
                throw new InvalidArgumentException("json_decode() raised error ".json_last_error());
        }
        return $this->jsonData;
    }

    /**
     * get status of the article number search
     *
     * @return string status
     **/
    public function getArticleNumberSearchStatus()
    {
        if ($this->articleNumberSearchStatus == null) {

            $this->isArticleNumberSearch = false;
            $this->articleNumberSearchStatus = self::NO_RESULT;

            if ($this->getStatus() != self::NO_RESULT) {
                $this->loadArticleNumberSearchInformations();
            }
        }
        return $this->articleNumberSearchStatus;
    }

    /**
     * returns true if the search was an article number search
     *
     * @return boolean isArticleNumberSearch
     **/
    public function isArticleNumberSearch()
    {
        if ($this->isArticleNumberSearch === null) {

            $this->isArticleNumberSearch = false;

            if ($this->getStatus() != self::NO_RESULT) {
                $this->loadArticleNumberSearchInformations();
            }
        }
        return $this->isArticleNumberSearch;
    }

    /**
     * fetch article number search status from the json result
     *
     * @return void
     */
    private function loadArticleNumberSearchInformations()
    {
        $jsonData = $this->getData();
        switch ($jsonData["searchResult"]["articleNumberSearchStatus"]) {
            case 'nothingFound':
                $this->isArticleNumberSearch = true;
                $this->articleNumberSearchStatus = self::NOTHING_FOUND;
                break;
            case 'resultsFound':
                $this->isArticleNumberSearch = true;
                $this->articleNumberSearchStatus = self::RESULTS_FOUND;
                break;
            case 'noArticleNumberSearch':
            default:
                $this->isArticleNumberSearch = false;
                $this->articleNumberSearchStatus = self::NO_RESULT;
        }
    }

    /**
     * returns true if the search-process was aborted because of a timeout
     *
     * @return boolean true if search timed out
     **/
    public function isSearchTimedOut()
    {
        $jsonData = $this->getData();
        return $jsonData['searchResult']['timedOut'];
    }

    /**
     * get search status
     *
     * @return string status
     **/
    public function getStatus()
    {
        $jsonData = $this->getData();
        if ($this->status == null) {
            switch ($jsonData['searchResult']['resultStatus']) {
                case 'nothingFound':
                    $this->status = self::NOTHING_FOUND;
                    break;
                case 'resultsFound':
                    $this->status = self::RESULTS_FOUND;
                    break;
                default:
                    $this->status = self::NO_RESULT;
            }
        }
        return $this->status;
    }

    protected function createSearchParams()
    {
        $breadCrumbTrail = $this->getBreadCrumbTrail();
        if (sizeof($breadCrumbTrail) > 0) {
            $paramString = $breadCrumbTrail[sizeof($breadCrumbTrail) - 1]->getUrl();
            $searchParams = $this->getParamsParser()->getFactfinderParamsFromString($paramString);
        } else {
            $searchParams = $this->getParamsParser()->getFactfinderParams();
        }
        return $searchParams;
    }
    /**
     * create result object
     **/
    protected function createResult()
    {
        return $this->getResultFromRawResult($this->getData());
    }

    protected function getResultFromRawResult($jsonData) {
        //init default values
        $result      = array();
        $resultCount = 0;

        $searchResultData = $jsonData['searchResult'];
        
        if (!empty($searchResultData['records'])) {
            $resultCount = (int)$searchResultData['resultCount'];
            $encodingHandler = $this->getEncodingHandler();

            $paging = $this->getPaging();
            $positionOffset = ($paging->getCurrentPageNumber() - 1) * $this->getProductsPerPageOptions()->getSelectedOption()->getValue();

            //load result
            $positionCounter = 1;
            foreach($searchResultData['records'] AS $recordData){
                // get current position
                $position = $positionOffset + $positionCounter;
                $positionCounter++;

                $result[] = $this->getRecordFromRawRecord($recordData, $position);
            }
        }
        return FF::getInstance('result', $result, $resultCount);
    }

	protected function getRecordFromRawRecord($recordData, $position)
	{
        $originalPosition = $position;
        
        $fieldValues = $recordData['record'];
        
        if (isset($fieldValues['__ORIG_POSITION__']))
        {
            $originalPosition = (int) $fieldValues['__ORIG_POSITION__'];
            unset($fieldValues['__ORIG_POSITION__']);
        }
        
        $record = FF::getInstance('record',
            strval($recordData['id']),
            $recordData['searchSimilarity'],
            $position,
            $originalPosition,
            $this->getEncodingHandler()->encodeServerContentForPage($fieldValues)
        );

		$record->setSeoPath(strval($recordData['seoPath']));

        foreach($recordData['keywords'] AS $keyword) {
            $record->addKeyword(strval($keyword));
        }
        
		return $record;
	}

    /**
     * @return FACTFinder_Asn
     **/
    protected function createAsn()
    {
        $asn = array();
        $jsonData = $this->getData();
        if (!empty($jsonData['searchResult']['groups'])) {
            $encodingHandler = $this->getEncodingHandler();

            foreach ($jsonData['searchResult']['groups'] AS $groupData) {
                $group = $this->createGroupInstance($groupData, $encodingHandler);
                
                $elements = array_merge($groupData['selectedElements'], $groupData['elements']);
                
                //get filters of the current group
                foreach ($elements AS $elementData) {
                    $filter = $this->createFilter($elementData, $group, $encodingHandler);

                    $group->addFilter($filter);
                }
                $asn[] = $group;
            }
        }
        return FF::getInstance('asn', $asn);
    }

    protected function createGroupInstance($groupData, $encodingHandler)
    {
        $groupName = $groupData['name'];
        $groupUnit = $groupData['unit'];
        
        return FF::getInstance('asnGroup',
            array(),
            $encodingHandler->encodeServerContentForPage($groupName),
            $groupData['detailedLinks'],
            $encodingHandler->encodeServerContentForPage($groupUnit),
            $groupData['filterStyle']
        );
    }

    protected function createFilter($elementData, $group, $encodingHandler)
    {
        $filterLink = $this->createLink($elementData);

        if ($group->isSliderStyle()) {
            // get last (empty) parameter from the search params property
            $params = $this->getParamsParser()->parseParamsFromResultString(trim($elementData['searchParams']));
            end($params);
            $filterLink .= '&' . key($params) . '=';

            $filter = FF::getInstance('asnSliderFilter',
                $filterLink,
                $elementData['absoluteMinValue'],
                $elementData['absoluteMaxValue'],
                $elementData['selectedMinValue'],
                $elementData['selectedMaxValue'],
                $elementData['associatedFieldName']
            );
        } else {
            $filter = FF::getInstance('asnFilterItem',
                $encodingHandler->encodeServerContentForPage($elementData['name']),
                $filterLink,
                $elementData['selected'],
                $elementData['recordCount'],
                $elementData['clusterLevel'],
                ($elementData['previewImageURL'] ? $elementData['previewImageURL'] : ''),
                $elementData['associatedFieldName']
            );
        }

        return $filter;
    }
    
    protected function createLink($item)
    {
        return $this->getParamsParser()->createPageLink(
            $this->getParamsParser()->parseParamsFromResultString(trim($item['searchParams']))
        );
    }

    /**
     * @return array of FACTFinder_SortItem objects
     **/
    protected function createSorting()
    {
        $sorting = array();
        $jsonData = $this->getData();

        $encodingHandler = $this->getEncodingHandler();
        foreach ($jsonData['searchResult']['sortsList'] AS $sortItemData) {
            $sortLink = $this->createLink($sortItemData);
            
            $sorting[] = FF::getInstance('item',
                $encodingHandler->encodeServerContentForPage(trim($sortItemData['description'])),
                $sortLink,
                $sortItemData['selected']
            );
        }
        return $sorting;
    }

    /**
     * @return array of FACTFinder_Item objects
     **/
    protected function createPaging()
    {
        $paging = null;
        $jsonData = $this->getData();
        $pagingData = $jsonData['searchResult']['paging'];
        if (!empty($pagingData)) {
            $paging = FF::getInstance('paging',
                $pagingData['currentPage'],
                $pagingData['pageCount'],
                $this->getParamsParser()
            );
        } else {
            $paging = FF::getInstance('paging', 1, 1, $this->getParamsParser());
        }
        return $paging;
    }

    /**
     * @return FACTFinder_ProductsPerPageOptions
     */
    protected function createProductsPerPageOptions()
    {
        $pppOptions = array(); //default
        $jsonData = $this->getData();
        
        if (!empty($jsonData['searchResult']['resultsPerPageList']))
        {
            $defaultOption = -1;
            $selectedOption = -1;
            $options = array();
            foreach ($jsonData['searchResult']['resultsPerPageList'] AS $optionData) {
                $value = $optionData['value'];
                
                if($optionData['default'])
                    $defaultOption = $value;
                if($optionData['selected'])
                    $selectedOption = $value;
                
                $url = $this->getParamsParser()->createPageLink(
                    $this->getParamsParser()->parseParamsFromResultString(trim($optionData['searchParams']))
                );
                $options[$value] = $url;
            }
            $pppOptions = FF::getInstance('productsPerPageOptions', $options, $defaultOption, $selectedOption);
        }
        return $pppOptions;
    }

    /**
     * @return array of FACTFinder_BreadCrumbItem objects
     */
    protected function createBreadCrumbTrail()
    {
        $breadCrumbTrail = array();
        $jsonData = $this->getData();
        
        $breadCrumbTrailData = $jsonData['searchResult']['breadCrumbTrailItems'];
        
        $encodingHandler = $this->getEncodingHandler();

        $i = 1;
        foreach($breadCrumbTrailData as $breadCrumbData)
        {
            $link = $this->createLink($breadCrumbData);
            
            $fieldName = '';
            
            $type = $encodingHandler->encodeServerContentForPage($breadCrumbData['type']);
            
            if ($type == 'filter') {
                $fieldName = $encodingHandler->encodeServerContentForPage($breadCrumbData['associatedFieldName']);
            }
            
            $breadCrumbTrail[] = FF::getInstance('breadCrumbItem',
                $encodingHandler->encodeServerContentForPage(trim($breadCrumbData['text'])),
                $link,
                ($i == count($breadCrumbTrailData)),
                $type,
                $fieldName,
                '' // The JSON response does not have a separate field for the unit but instead includes
                   // it in the "text" field.
            );
            ++$i;
        }
        
        return $breadCrumbTrail;
    }


    /**
     * @return array of FACTFinder_Campaign objects
     */
    protected function createCampaigns()
    {
        $campaigns = array();
        $jsonData = $this->getData();
        
        if (isset($jsonData['campaigns'])) {
            $encodingHandler = $this->getEncodingHandler();

            foreach ($jsonData['campaigns'] as $campaignData) {
                $campaign = $this->createEmptyCampaignObject($campaignData, $encodingHandler);
                
                $this->fillCampaignObject($campaign, $campaignData, $encodingHandler);
                
                $campaigns[] = $campaign;
            }
        }
        $campaignIterator = FF::getInstance('campaignIterator', $campaigns);
        return $campaignIterator;
    }
    
    protected function createEmptyCampaignObject($campaignData, $encodingHandler)
    {
        return FF::getInstance('campaign',
            $encodingHandler->encodeServerContentForPage($campaignData['name']),
            $encodingHandler->encodeServerContentForPage($campaignData['category']),
            $encodingHandler->encodeServerUrlForPageUrl($campaignData['target']['destination'])
        );
    }
    
    protected function fillCampaignObject($campaign, $campaignData, $encodingHandler)
    {
        $this->fillCampaignWithFeedback($campaign, $campaignData, $encodingHandler);
        $this->fillCampaignWithPushedProducts($campaign, $campaignData, $encodingHandler);
    }
    
    protected function fillCampaignWithFeedback($campaign, $campaignData, $encodingHandler)
    {
        $campaign->addFeedback($encodingHandler->encodeServerContentForPage($campaignData['feedbackTexts']));
    }
    
    protected function fillCampaignWithPushedProducts($campaign, $campaignData, $encodingHandler)
    {
        if (!empty($campaignData['pushedProducts'])) {
            $pushedProducts = array();
            foreach ($campaignData['pushedProducts'] AS $recordData) {
                $fieldName = $recordData['field'];
                $fieldValue = $recordData['name'];
                $jsonData = $this->getData();
                foreach ($jsonData['pushedProducts'] as $pushedProductData)
                {
                    if ($pushedProductData['record'][$fieldName] == $fieldValue)
                    {
                    $record = FF::getInstance('record', $pushedProductData['id']);
                    $record->setValues($encodingHandler->encodeServerContentForPage($pushedProductData['record']));
                    
                    $pushedProducts[] = $record;
                    break;
                    }
                }
            }
            $campaign->addPushedProducts($pushedProducts);
        }
    }

    /**
     * @return array of FACTFinder_SingleWordSearchItem objects
     */
    protected function createSingleWordSearch()
	{
        $xmlResult = $this->getData();
        $singleWordSearch = array();
        if (isset($xmlResult->singleWordSearch)) {
            $encodingHandler = $this->getEncodingHandler();
            foreach ($xmlResult->singleWordSearch->item AS $item) {
                $query = $encodingHandler->encodeServerContentForPage(strval($item->attributes()->word));
                $singleWordSearchItem = FF::getInstance('singleWordSearchItem',
                    $query,
                    $this->getParamsParser()->createPageLink(array('query' => $query)),
                    intval(trim($item->attributes()->count))
                );

				//add preview records
				if (isset($item->record)) {
					$position = 1;
					foreach($item->record AS $rawRecord) {
						$record = $this->getRecordFromRawRecord($rawRecord, $position);
						$singleWordSearchItem->addPreviewRecord($record);
						$position++;
					}
				}

				$singleWordSearch[] = $singleWordSearchItem;
            }
        }
        return $singleWordSearch;
    }

    /**
     * get error if there is one
     *
     * @return string if error exists, else null
     */
    public function getError()
    {
        $error = null;
        $xmlResult = $this->getData();
        if (!empty($xmlResult->error)) {
            $error = trim(strval($xmlResult->error));
        }
        return $error;
    }

    /**
     * get stacktrace if there is one
     *
     * @return string if stacktrace exists, else null
     */
    public function getStackTrace()
    {
        $stackTrace = null;
        $xmlResult = $this->getData();
        if (!empty($xmlResult->stacktrace)) {
            $stackTrace = trim(strval($xmlResult->stacktrace));
        }
        return $stackTrace;
    }
}