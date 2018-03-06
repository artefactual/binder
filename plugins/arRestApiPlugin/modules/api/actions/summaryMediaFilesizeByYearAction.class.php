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

class ApiSummaryMediaFilesizeByYearAction extends QubitApiAction
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

    // Create nested aggregation to get average size by year,
    // extend bounds to get results until the current year;
    // a min value is required in the bounds but it doesn't
    // reduce the bounds if there are results before it.
    $currentYear = (int)date('Y');
    $aggName = 'collection_year_file_stats';
    $aggOptions = array(
      'interval' => 1,
      'params' => array(
        array(
          'name' => 'extended_bounds',
          'value' => array('min' => $currentYear, 'max' => $currentYear)
        )
      )
    );
    $agg = $this->buildEsAgg('Histogram', $aggName, 'partOf.year_collected', $aggOptions);
    $agg->addAggregation($this->buildEsAgg('Avg', 'size', 'sizeOnDisk'));
    $query->addAggregation($agg);

    try
    {
      $resultSet = QubitSearch::getInstance()->index->getType('QubitAip')->search($query);
    }
    catch (Exception $e)
    {
      return array();
    }

    $agg = $resultSet->getAggregation($aggName);
    $results = array();

    foreach($agg['buckets'] as $bucket)
    {
      $results[] = array(
        'year' => $bucket['key'],
        'average' => $bucket['size']['value'] ? $bucket['size']['value'] : 0
      );
    }

    return $results;
  }
}
