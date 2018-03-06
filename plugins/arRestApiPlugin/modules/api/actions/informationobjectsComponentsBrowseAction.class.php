<?php

/*
 * This file is part of the Access to Memory (AtoM) software.
 *
 * Access to Memory (AtoM) is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Access to Memory (AtoM) is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Access to Memory (AtoM).  If not, see <http://www.gnu.org/licenses/>.
 */

class ApiInformationObjectsComponentsBrowseAction extends QubitApiAction
{
  protected function get($request)
  {
    return $this->getResults();
  }

  protected function getResults()
  {
    // Create query objects
    $query = new \Elastica\Query;
    $queryBool = new \Elastica\Query\BoolQuery;

    // Pagination and sorting
    $this->prepareEsPagination($query);
    $this->prepareEsSorting($query, array(
      'createdAt' => 'createdAt'));

    // Filter to TMS Components
    $componentLevels = array(
      sfConfig::get('app_drmc_lod_archival_master_id'),
      sfConfig::get('app_drmc_lod_artist_supplied_master_id'),
      sfConfig::get('app_drmc_lod_artist_verified_proof_id'),
      sfConfig::get('app_drmc_lod_exhibition_format_id'),
      sfConfig::get('app_drmc_lod_miscellaneous_id'),
      sfConfig::get('app_drmc_lod_component_id')
    );

    $queryBool->addMust(new \Elastica\Query\Terms('levelOfDescriptionId', $componentLevels));

    // Filter query
    if (isset($this->request->query) && 1 !== preg_match('/^[\s\t\r\n]*$/', $this->request->query))
    {
      $culture = sfContext::getInstance()->user->getCulture();

      $queryFields = array(
        'i18n.'.$culture.'.title.autocomplete',
        'identifier',
        'i18n.'.$culture.'.extentAndMedium',
        'i18n.'.$culture.'.physicalCharacteristics',
        'tmsComponent.componentNumber',
        'tmsComponent.installComments.i18n.'.$culture.'.content',
        'tmsComponent.prepComments.i18n.'.$culture.'.content',
        'tmsComponent.storageComments.i18n.'.$culture.'.content',
        'tmsComponent.textEntries.i18n.'.$culture.'.content',
        'tmsComponent.artwork.i18n.'.$culture.'.title'
      );

      $queryText = new \Elastica\Query\QueryString($this->request->query);
      $queryText->setFields($queryFields);

      $queryBool->addMust($queryText);
    }

    // Filter materials total size using a Painless script to calculate total size.
    // An aggregation can't be made over an scripted field so it requires to do the
    // total size calculation twice, for the aggregation and for the query.
    if ((isset($this->request->totalSizeFrom) && ctype_digit($this->request->totalSizeFrom))
      || (isset($this->request->totalSizeTo) && ctype_digit($this->request->totalSizeTo)))
    {
      $scriptCode = <<<Painless
double total = 0;
for (size in doc['aips.sizeOnDisk'])
  total += size;
if (params.containsKey('from') && params.containsKey('to'))
  total > params.from && total < params.to;
else if (params.containsKey('from'))
  total > params.from;
else if (params.containsKey('to'))
  total < params.to;
Painless;

      $script = new \Elastica\Script\Script($scriptCode);

      if (isset($this->request->totalSizeFrom))
      {
        $script->setParam('from', (double)$this->request->totalSizeFrom);
      }

      if (isset($this->request->totalSizeTo))
      {
        $script->setParam('to', (double)$this->request->totalSizeTo);
      }

      $scriptQuery = new \Elastica\Query\Script($script);

      $queryBool->addMust($scriptQuery);
    }

    // Filter other selected aggs
    $this->filterEsQuery('classification', 'tmsComponent.type.id', $queryBool);
    $this->filterEsQuery('type', 'levelOfDescriptionId', $queryBool);

    $this->filterEsRangeQuery('ingestedFrom', 'ingestedTo', 'aips.createdAt', $queryBool);

    $this->filterEsQuery('format', 'aips.digitalObjects.metsData.format.name', $queryBool, 'AND', array('noInteger' => true));
    $this->filterEsQuery('videoCodec', 'aips.digitalObjects.metsData.mediainfo.videoTracks.codec', $queryBool, 'AND', array('noInteger' => true));
    $this->filterEsQuery('audioCodec', 'aips.digitalObjects.metsData.mediainfo.audioTracks.codec', $queryBool, 'AND', array('noInteger' => true));
    $this->filterEsQuery('resolution', 'aips.digitalObjects.metsData.mediainfo.videoTracks.resolution', $queryBool);
    $this->filterEsQuery('chromaSubSampling', 'aips.digitalObjects.metsData.mediainfo.videoTracks.chromaSubsampling', $queryBool, 'AND', array('noInteger' => true));
    $this->filterEsQuery('colorSpace', 'aips.digitalObjects.metsData.mediainfo.videoTracks.colorSpace', $queryBool, 'AND', array('noInteger' => true));
    $this->filterEsQuery('sampleRate', 'aips.digitalObjects.metsData.mediainfo.audioTracks.samplingRate', $queryBool);
    $this->filterEsQuery('bitDepth', 'aips.digitalObjects.metsData.mediainfo.videoTracks.bitDepth', $queryBool);

    // Add aggregations to the query
    $query->addAggregation($this->buildEsAgg('Terms', 'format', 'aips.digitalObjects.metsData.format.name'));
    $query->addAggregation($this->buildEsAgg('Terms', 'videoCodec', 'aips.digitalObjects.metsData.mediainfo.videoTracks.codec'));
    $query->addAggregation($this->buildEsAgg('Terms', 'audioCodec', 'aips.digitalObjects.metsData.mediainfo.audioTracks.codec'));
    $query->addAggregation($this->buildEsAgg('Terms', 'resolution', 'aips.digitalObjects.metsData.mediainfo.videoTracks.resolution'));
    $query->addAggregation($this->buildEsAgg('Terms', 'chromaSubSampling', 'aips.digitalObjects.metsData.mediainfo.videoTracks.chromaSubsampling'));
    $query->addAggregation($this->buildEsAgg('Terms', 'colorSpace', 'aips.digitalObjects.metsData.mediainfo.videoTracks.colorSpace'));
    $query->addAggregation($this->buildEsAgg('Terms', 'sampleRate', 'aips.digitalObjects.metsData.mediainfo.audioTracks.samplingRate'));
    $query->addAggregation($this->buildEsAgg('Terms', 'bitDepth', 'aips.digitalObjects.metsData.mediainfo.videoTracks.bitDepth'));

    $query->addAggregation($this->buildEsAgg('Terms', 'classification', 'tmsComponent.type.id'));
    $query->addAggregation($this->buildEsAgg('Terms', 'type', 'levelOfDescriptionId'));

    $now = new DateTime();
    $now->setTime(0, 0);

    $dateRanges = array(
      array(
        'from' => null,
        'to' => $now->modify('-1 year')->getTimestamp().'000',
        'key' => 'Older than a year'
      ),
      array(
        'from' => $now->getTimestamp().'000',
        'to' => null,
        'key' => 'From last year'
      ),
      array(
        'from' => $now->modify('+11 months')->getTimestamp().'000',
        'to' => null,
        'key' => 'From last month'
      ),
      array(
        'from' => $now->modify('+1 month')->modify('-7 days')->getTimestamp().'000',
        'to' => null,
        'key' => 'From last week'
      )
    );

    $query->addAggregation($this->buildEsAgg(
      'DateRange',
      'dateIngested',
      'aips.createdAt',
      array('ranges' => $dateRanges)
    ));

    // Add aggregation for materials total size using a Painless script to
    // calculate total size. An aggregation can't be made over an scripted
    // field so it requires to do the total size calculation twice, for the
    // aggregation and for the query.
    $scriptCode = <<<Painless
double total = 0;
for (size in doc['aips.sizeOnDisk'])
  total += size;
return total;
Painless;

    $sizeRanges = array(
      array('from' => null, 'to' => 512000, 'key' => 'Smaller than 500 KB'),
      array('from' => 512000, 'to' => 1048576, 'key' => 'Between 500 KB and 1 MB'),
      array('from' => 1048576, 'to' => 2097152, 'key' => 'Between 1 MB and 2 MB'),
      array('from' => 2097152, 'to' => 5242880, 'key' => 'Between 2 MB and 5 MB'),
      array('from' => 5242880, 'to' => 10485760, 'key' => 'Between 5 MB and 10 MB'),
      array('from' => 10485760, 'to' => null, 'key' => 'Bigger than 10 MB')
    );

    $query->addAggregation($this->buildEsAgg(
      'Range',
      'totalSize',
      null,
      array(
        'ranges' => $sizeRanges,
        'script' => $scriptCode
      )
    ));

    // Limit fields
    $query->setSource(array(
      'slug',
      'identifier',
      'inheritReferenceCode',
      'levelOfDescriptionId',
      'publicationStatusId',
      'ancestors',
      'parentId',
      'hasDigitalObject',
      'createdAt',
      'updatedAt',
      'sourceCulture',
      'i18n',
      'tmsComponent',
      'aips',
      'artwork'));

    $query->setQuery($queryBool);

    $resultSet = QubitSearch::getInstance()->index->getType('QubitInformationObject')->search($query);

    // Build array from results
    $results = array();
    foreach ($resultSet as $hit)
    {
      $doc = $hit->getData();
      $result = array();

      $this->addItemToArray($result, 'id', $doc['identifier']);
      $this->addItemToArray($result, 'name', get_search_i18n($doc, 'title'));
      $this->addItemToArray($result, 'phys_desc', get_search_i18n($doc, 'extentAndMedium'));
      $this->addItemToArray($result, 'dimensions', get_search_i18n($doc, 'physicalCharacteristics'));
      $this->addItemToArray($result, 'type', get_search_i18n($doc['tmsComponent']['type'][0], 'name'));
      $this->addItemToArray($result, 'install_comments', get_search_i18n($doc['tmsComponent']['installComments'][0], 'content'));
      $this->addItemToArray($result, 'prep_comments', get_search_i18n($doc['tmsComponent']['prepComments'][0], 'content'));
      $this->addItemToArray($result, 'storage_comments', get_search_i18n($doc['tmsComponent']['storageComments'][0], 'content'));
      $this->addItemToArray($result, 'text_entries', get_search_i18n($doc['tmsComponent']['textEntries'][0], 'content'));
      $this->addItemToArray($result, 'count', $doc['tmsComponent']['compCount']);
      $this->addItemToArray($result, 'number', $doc['tmsComponent']['componentNumber']);
      $this->addItemToArray($result, 'lod_name', $this->getAggLabel('classification', $doc['levelOfDescriptionId']));
      $this->addItemToArray($result, 'artwork_id', $doc['tmsComponent']['artwork']['id']);
      $this->addItemToArray($result, 'artwork_title', get_search_i18n($doc['tmsComponent']['artwork'], 'title'));
      $this->addItemToArray($result, 'artwork_thumbnail', $doc['tmsComponent']['artwork']['thumbnail']);

      $results[$hit->getId()] = $result;
    }

    $aggs = $resultSet->getAggregations();
    $this->formatAggs($aggs);

    return
      array(
        'total' => $resultSet->getTotalHits(),
        'aggs' => $aggs,
        'results' => $results);
  }

  protected function getAggLabel($name, $id)
  {
    switch ($name)
    {
      case 'classification':
      case 'type':
        if (null !== $item = QubitTerm::getById($id))
        {
          return $item->getName(array('cultureFallback' => true));
        }

        break;

      case 'format':
      case 'videoCodec':
      case 'audioCodec':
      case 'chromaSubSampling':
      case 'colorSpace':
        return $id;

        break;

      case 'resolution':
      case 'bitDepth':
        return $id.' bits';

        break;

      case 'sampleRate':
        return $id.' Hz';

        break;
    }
  }
}
