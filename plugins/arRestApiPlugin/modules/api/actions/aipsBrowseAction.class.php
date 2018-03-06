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

class ApiAipsBrowseAction extends QubitApiAction
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
    $queryBool->addMust(new \Elastica\Query\MatchAll);

    // Pagination and sorting
    $this->prepareEsPagination($query);
    $this->prepareEsSorting($query, array(
      'name' => 'filename.untouched',
      'size' => 'sizeOnDisk',
      'createdAt' => 'createdAt'));

    // Filter query
    if (isset($this->request->query) && 1 !== preg_match('/^[\s\t\r\n]*$/', $this->request->query))
    {
      $culture = sfContext::getInstance()->user->getCulture();

      $queryFields = array(
        'filename.autocomplete',
        'uuid',
        'partOf.i18n.'.$culture.'.title'
      );

      $queryText = new \Elastica\Query\QueryString($this->request->query);
      $queryText->setFields($queryFields);

      $queryBool->addMust($queryText);
    }

    // Filter selected aggregations
    $this->filterEsQuery('type', 'type.id', $queryBool);

    $this->filterEsRangeQuery('sizeFrom', 'sizeTo', 'sizeOnDisk', $queryBool);
    $this->filterEsRangeQuery('ingestedFrom', 'ingestedTo', 'createdAt', $queryBool);

    $this->filterEsQuery('format', 'digitalObjects.metsData.format.name', $queryBool, 'AND', array('noInteger' => true));
    $this->filterEsQuery('videoCodec', 'digitalObjects.metsData.mediainfo.videoTracks.codec', $queryBool, 'AND', array('noInteger' => true));
    $this->filterEsQuery('audioCodec', 'digitalObjects.metsData.mediainfo.audioTracks.codec', $queryBool, 'AND', array('noInteger' => true));
    $this->filterEsQuery('resolution', 'digitalObjects.metsData.mediainfo.videoTracks.resolution', $queryBool);
    $this->filterEsQuery('chromaSubSampling', 'digitalObjects.metsData.mediainfo.videoTracks.chromaSubsampling', $queryBool, 'AND', array('noInteger' => true));
    $this->filterEsQuery('colorSpace', 'digitalObjects.metsData.mediainfo.videoTracks.colorSpace', $queryBool, 'AND', array('noInteger' => true));
    $this->filterEsQuery('sampleRate', 'digitalObjects.metsData.mediainfo.audioTracks.samplingRate', $queryBool);
    $this->filterEsQuery('bitDepth', 'digitalObjects.metsData.mediainfo.videoTracks.bitDepth', $queryBool);

    // Add aggregations to the query
    $query->addAggregation($this->buildEsAgg('Terms', 'format', 'digitalObjects.metsData.format.name'));
    $query->addAggregation($this->buildEsAgg('Terms', 'videoCodec', 'digitalObjects.metsData.mediainfo.videoTracks.codec'));
    $query->addAggregation($this->buildEsAgg('Terms', 'audioCodec', 'digitalObjects.metsData.mediainfo.audioTracks.codec'));
    $query->addAggregation($this->buildEsAgg('Terms', 'resolution', 'digitalObjects.metsData.mediainfo.videoTracks.resolution'));
    $query->addAggregation($this->buildEsAgg('Terms', 'chromaSubSampling', 'digitalObjects.metsData.mediainfo.videoTracks.chromaSubsampling'));
    $query->addAggregation($this->buildEsAgg('Terms', 'colorSpace', 'digitalObjects.metsData.mediainfo.videoTracks.colorSpace'));
    $query->addAggregation($this->buildEsAgg('Terms', 'sampleRate', 'digitalObjects.metsData.mediainfo.audioTracks.samplingRate'));
    $query->addAggregation($this->buildEsAgg('Terms', 'bitDepth', 'digitalObjects.metsData.mediainfo.videoTracks.bitDepth'));

    $query->addAggregation($this->buildEsAgg('Terms', 'type', 'type.id'));

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
      'sizeOnDisk',
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
      'createdAt',
      array('ranges' => $dateRanges)
    ));

    // Assign query
    $query->setQuery($queryBool);

    $resultSet = QubitSearch::getInstance()->index->getType('QubitAip')->search($query);

    $data = array();
    foreach ($resultSet as $hit)
    {
      $doc = $hit->getData();

      $aip = array();

      $this->addItemToArray($aip, 'id', $hit->getId());
      $this->addItemToArray($aip, 'name', $doc['filename']);
      $this->addItemToArray($aip, 'uuid', $doc['uuid']);
      $this->addItemToArray($aip, 'size', $doc['sizeOnDisk']);
      $this->addItemToArray($aip, 'created_at', arRestApiPluginUtils::convertDate($doc['createdAt']));

      if (isset($doc['type']))
      {
        $this->addItemToArray($aip['type'], 'id', $doc['type']['id']);
        $this->addItemToArray($aip['type'], 'name', get_search_i18n($doc['type'], 'name'));
      }

      if (isset($doc['partOf']))
      {
        $this->addItemToArray($aip['part_of'], 'id', $doc['partOf']['id']);
        $this->addItemToArray($aip['part_of'], 'title', get_search_i18n($doc['partOf'], 'title'));
        $this->addItemToArray($aip['part_of'], 'level_of_description_id', $doc['partOf']['levelOfDescriptionId']);
      }

      $this->addItemToArray($aip, 'digital_object_count', $doc['digitalObjectCount']);

      $data['results'][] = $aip;
    }

    $aggs = $resultSet->getAggregations();
    $this->formatAggs($aggs);

    $data['aggs'] = $aggs;
    $data['total'] = $resultSet->getTotalHits();
    $data['overview'] = $this->getAipsOverview();

    return $data;
  }

  protected function getAggLabel($name, $id)
  {
    switch ($name)
    {
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
