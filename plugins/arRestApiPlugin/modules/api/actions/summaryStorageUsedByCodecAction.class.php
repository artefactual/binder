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

class ApiSummaryStorageUsedByCodecAction extends QubitApiAction
{
  protected function get($request)
  {
    return array('results' => $this->getResults());
  }

  protected function getResults()
  {
    $query = new \Elastica\Query;
    $query->setQuery(new \Elastica\Query\MatchAll);

    // We don't need details, just aggregation results
    $query->setSize(0);

    // Add nested aggregations to calculate total bytes used per codec
    $codecTypes = array(
      'general_track_stats' => 'generalTracks',
      'video_track_stats'   => 'videoTracks',
      'audio_track_stats'   => 'audioTracks'
    );

    foreach($codecTypes as $aggName => $mediaInfoPropName)
    {
      $agg = $this->buildEsAgg('Terms', $aggName, 'digitalObjects.metsData.mediainfo.'. $mediaInfoPropName .'.codec');
      $agg->addAggregation($this->buildEsAgg('Sum', 'size', 'digitalObjects.metsData.size'));
      $query->addAggregation($agg);
    }

    try
    {
      $resultSet = QubitSearch::getInstance()->index->getType('QubitAip')->search($query);
    }
    catch (Exception $e)
    {
      return array();
    }

    $results = array();

    foreach($codecTypes as $aggName => $mediaInfoPropName)
    {
      $agg = $resultSet->getAggregation($aggName);
      foreach($agg['buckets'] as $bucket)
      {
        $results[] = array(
          'codec' => strtoupper($bucket['key']),
          'total' => $bucket['size']['value']
        );
      }
    }

    return $results;
  }
}
