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

class ApiSummaryArtworkByDateAction extends QubitApiAction
{
  protected function get($request)
  {
    return array('results' => $this->getResults());
  }

  protected function getResults()
  {
    $query = new \Elastica\Query;

    // Get all artwork records
    $queryMatch = new \Elastica\Query\Match;
    $queryMatch->setField(
      'levelOfDescriptionId',
      sfConfig::get('app_drmc_lod_artwork_record_id')
    );

    $query->setQuery($queryMatch);

    // We don't need details, just aggregation results
    $query->setSize(0);

    // Add aggregations to get the count and total of artworks
    // per year or month by creation and collected date
    $now = round(microtime(true) * 1000);
    $aggs = array(
      'collection' => array(
        'field' => 'tmsObject.dateCollected',
        'interval' => 'year',
        'format' => 'yyyy'
      ),
      'creation' => array(
        'field' => 'createdAt',
        'interval' => 'month',
        'format' => 'yyyy-MM'
      )
    );

    foreach ($aggs as $name => $options)
    {
      $aggOptions = array(
        'interval' => $options['interval'],
        'params' => array(
          array(
            'name' => 'extended_bounds',
            'value' => array( 'min' => $now, 'max' => $now)
          ),
          array(
            'name' => 'format',
            'value' => $options['format']
          )
        )
      );
      $agg = $this->buildEsAgg('DateHistogram', $name, $options['field'], $aggOptions);
      $query->addAggregation($agg);
    }

    // Cumulative sum aggregation to calculate running totals.
    // Elastica doesn't include a CumulativeSum aggregation,
    // therefore we modify the raw query adding the nested aggs.
    $cumulativeAgg = array(
      'total' => array(
        'cumulative_sum' => array(
          'buckets_path' => '_count'
        )
      )
    );

    $rawQuery = $query->toArray();
    foreach ($aggs as $name => $options)
    {
      $rawQuery['aggs'][$name]['aggs'] = $cumulativeAgg;
    }

    $query->setRawQuery($rawQuery);

    try
    {
      $resultSet = QubitSearch::getInstance()->index->getType('QubitInformationObject')->search($query);
    }
    catch (Exception $e)
    {
      return array(
        'creation' => array(),
        'collection' => array()
      );
    }

    $results = array();

    foreach ($aggs as $name => $options)
    {
      $agg = $resultSet->getAggregation($name);
      $aggResults = array();

      foreach ($agg['buckets'] as $bucket)
      {
        $yearMonth = split('-', $bucket['key_as_string']);
        $aggResults[] = array(
          'year' => $yearMonth[0],
          'month' => ltrim($yearMonth[1], '0'),
          'count' => $bucket['doc_count'],
          'total' => $bucket['total']['value']
        );
      }

      $results[$name] = $aggResults;
    }

    return $results;
  }
}
