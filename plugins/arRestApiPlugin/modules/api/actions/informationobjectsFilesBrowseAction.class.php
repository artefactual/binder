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

class ApiInformationObjectsFilesBrowseAction extends QubitApiAction
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

    // Filter to digital objects
    $queryBool->addMust(new \Elastica\Query\Term(array('levelOfDescriptionId' => sfConfig::get('app_drmc_lod_digital_object_id'))));

    // Filter query
    if (isset($this->request->query) && 1 !== preg_match('/^[\s\t\r\n]*$/', $this->request->query))
    {
      $culture = sfContext::getInstance()->user->getCulture();

      $queryFields = array(
        'i18n.'.$culture.'.title.autocomplete',
        'identifier',
        'aipUuid',
        'aipName'
      );

      $queryText = new \Elastica\Query\QueryString($this->request->query);
      $queryText->setFields($queryFields);

      $queryBool->addMust($queryText);
    }

    // Filter selected aggregations
    $this->filterEsRangeQuery('sizeFrom', 'sizeTo', 'metsData.size', $queryBool);
    $this->filterEsRangeQuery('ingestedFrom', 'ingestedTo', 'metsData.dateIngested', $queryBool);
    $this->filterEsQuery('format', 'metsData.format.name', $queryBool, 'AND', array('noInteger' => true));
    $this->filterEsQuery('videoCodec', 'metsData.mediainfo.videoTracks.codec', $queryBool, 'AND', array('noInteger' => true));
    $this->filterEsQuery('audioCodec', 'metsData.mediainfo.audioTracks.codec', $queryBool, 'AND', array('noInteger' => true));
    $this->filterEsQuery('resolution', 'metsData.mediainfo.videoTracks.resolution', $queryBool);
    $this->filterEsQuery('chromaSubSampling', 'metsData.mediainfo.videoTracks.chromaSubsampling', $queryBool, 'AND', array('noInteger' => true));
    $this->filterEsQuery('colorSpace', 'metsData.mediainfo.videoTracks.colorSpace', $queryBool, 'AND', array('noInteger' => true));
    $this->filterEsQuery('sampleRate', 'metsData.mediainfo.audioTracks.samplingRate', $queryBool);
    $this->filterEsQuery('bitDepth', 'metsData.mediainfo.videoTracks.bitDepth', $queryBool);

    // Add aggregations to the query
    $query->addAggregation($this->buildEsAgg('Terms', 'format', 'metsData.format.name'));
    $query->addAggregation($this->buildEsAgg('Terms', 'videoCodec', 'metsData.mediainfo.videoTracks.codec'));
    $query->addAggregation($this->buildEsAgg('Terms', 'audioCodec', 'metsData.mediainfo.audioTracks.codec'));
    $query->addAggregation($this->buildEsAgg('Terms', 'resolution', 'metsData.mediainfo.videoTracks.resolution'));
    $query->addAggregation($this->buildEsAgg('Terms', 'chromaSubSampling', 'metsData.mediainfo.videoTracks.chromaSubsampling'));
    $query->addAggregation($this->buildEsAgg('Terms', 'colorSpace', 'metsData.mediainfo.videoTracks.colorSpace'));
    $query->addAggregation($this->buildEsAgg('Terms', 'sampleRate', 'metsData.mediainfo.audioTracks.samplingRate'));
    $query->addAggregation($this->buildEsAgg('Terms', 'bitDepth', 'metsData.mediainfo.videoTracks.bitDepth'));

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
      'size',
      'metsData.size',
      array('ranges' => $sizeRanges)
    ));

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
      'metsData.dateIngested',
      array('ranges' => $dateRanges)
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
      'dates',
      'creators',
      'metsData',
      'digitalObject',
      'aipUuid',
      'aipName',
      'originalRelativePathWithinAip'));

    // Assign query
    $query->setQuery($queryBool);

    $resultSet = QubitSearch::getInstance()->index->getType('QubitInformationObject')->search($query);

    // Build array from results
    $results = array();
    foreach ($resultSet as $hit)
    {
      $doc = $hit->getData();
      $result = array();

      $result['id'] = (int)$hit->getId();

      $this->addItemToArray($result, 'identifier', $doc['identifier']);
      $this->addItemToArray($result, 'filename', get_search_i18n($doc, 'title'));
      $this->addItemToArray($result, 'slug', $doc['slug']);
      $this->addItemToArray($result, 'media_type_id', $doc['digitalObject']['mediaTypeId']);
      $this->addItemToArray($result, 'byte_size', $doc['digitalObject']['byteSize']);
      $this->addItemToArray($result, 'size_in_aip', $doc['metsData']['size']);
      $this->addItemToArray($result, 'date_ingested', $doc['metsData']['dateIngested']);
      $this->addItemToArray($result, 'mime_type', $doc['digitalObject']['mimeType']);

      if (isset($doc['digitalObject']['thumbnailPath']))
      {
        $this->addItemToArray($result, 'thumbnail_path', image_path($doc['digitalObject']['thumbnailPath'], true));
      }

      if (isset($doc['digitalObject']['masterPath']))
      {
        $this->addItemToArray($result, 'master_path', image_path($doc['digitalObject']['masterPath'], true));
      }

      if (isset($doc['digitalObject']['mediaTypeId']) && !empty($doc['digitalObject']['mediaTypeId']))
      {
        $this->addItemToArray($result, 'media_type', $this->getAggLabel('mediaType', $doc['digitalObject']['mediaTypeId']));
      }

      $this->addItemToArray($result, 'aip_uuid', $doc['aipUuid']);
      $this->addItemToArray($result, 'aip_title', $doc['aipName']);
      $this->addItemToArray($result, 'original_relative_path_within_aip', $doc['originalRelativePathWithinAip']);

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
      case 'mediaType':
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
