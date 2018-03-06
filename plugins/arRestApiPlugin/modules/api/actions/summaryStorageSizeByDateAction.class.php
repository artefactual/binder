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

class ApiSummaryStorageSizeByDateAction extends QubitApiAction
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

    // Add aggregation to show sum and totals of size
    // by month based on creation date
    $now = round(microtime(true) * 1000);
    $aggOptions = array(
      'interval' => 'month',
      'params' => array(
        array(
          'name' => 'extended_bounds',
          'value' => array( 'min' => $now, 'max' => $now)
        ),
        array(
          'name' => 'format',
          'value' => 'yyyy-MM'
        )
      )
    );
    $agg = $this->buildEsAgg('DateHistogram', 'storageSize', 'createdAt', $aggOptions);
    $agg->addAggregation($this->buildEsAgg('Sum', 'size', 'sizeOnDisk'));
    $query->addAggregation($agg);

    // Cumulative sum aggregation to calculate running totals.
    // Elastica doesn't include a CumulativeSum aggregation,
    // therefore we modify the raw query adding the nested aggs.
    $cumulativeAgg = array(
      'total' => array(
        'cumulative_sum' => array(
          'buckets_path' => 'size'
        )
      )
    );

    $rawQuery = $query->toArray();
    $rawQuery['aggs']['storageSize']['aggs'] = array_merge($rawQuery['aggs']['storageSize']['aggs'], $cumulativeAgg);

    $query->setRawQuery($rawQuery);

    try
    {
      $resultSet = QubitSearch::getInstance()->index->getType('QubitAip')->search($query);
    }
    catch (Exception $e)
    {
      return array();
    }

    $agg = $resultSet->getAggregation('storageSize');
    $results = array();

    foreach ($agg['buckets'] as $bucket)
    {
      $yearMonth = split('-', $bucket['key_as_string']);
      $results[] = array(
        'year' => $yearMonth[0],
        'month' => ltrim($yearMonth[1], '0'),
        'count' => $bucket['size']['value'],
        'total' => $bucket['total']['value']
      );
    }

    return $results;
  }
}
