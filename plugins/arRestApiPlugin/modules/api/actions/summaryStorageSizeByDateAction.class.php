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
    // Create query object
    $query = new \Elastica\Query;
    $query->setQuery(new \Elastica\Query\MatchAll);

    // We don't need details, just facet results
    $query->setLimit(0);

    // Add facets to the months in which artwork records were collected and created
    $this->facetEsQuery('DateHistogram', 'storageSize', 'createdAt', $query, array('interval' => 'month', 'valueField' => 'sizeOnDisk'));

    // Return empty results if search fails
    try
    {
      $resultSet = QubitSearch::getInstance()->index->getType('QubitAip')->search($query);
    }
    catch (Exception $e)
    {
      return array();
    }

    $facets = $resultSet->getFacets();

    // Convert timestamps and calculate running total.
    // Add missing intervals: ElasticSearch 0.9 facets
    // don't include intervals without data, it can be solved
    // using aggregations in Elasticsearch 1.x.
    $results = array();
    $total = 0;
    foreach ($facets['storageSize']['entries'] as $entry)
    {
      // Calculate running total
      $total += $entry['total'];

      // Create result from entry
      $result = array();
      $result['total'] = $total;
      $result['count'] = $entry['total'];

      // Convert millisecond timestamps to years and months
      $date = date('Y-m-d', $entry['time'] / 1000);
      $result['year'] = (integer)substr($date, 0, 4);
      $result['month'] = (integer)substr($date, 5, 2);

      if (isset($previousResult))
      {
        // Add missing months in between to creation results
        while (($result['year'] === $previousResult['year'] &&
          $result['month'] - $previousResult['month'] > 1) ||
          $result['year'] - $previousResult['year'] > 1 ||
          ($result['year'] - $previousResult['year'] === 1 &&
          $result['month'] - $previousResult['month'] !== -11))
        {
          $missingResult = array();
          $missingResult['total'] = $previousResult['total'];
          $missingResult['count'] = 0;

          if ($previousResult['month'] == 12)
          {
            $missingResult['year'] = $previousResult['year'] + 1;
            $missingResult['month'] = 1;
          }
          else
          {
            $missingResult['year'] = $previousResult['year'];
            $missingResult['month'] = $previousResult['month'] + 1;
          }

          $results[] = $missingResult;
          $previousResult = $missingResult;
        }
      }

      $results[] = $result;
      $previousResult = $result;
    }

    $today = date('Y-m-d');
    $currentYear = (integer)substr($today, 0, 4);
    $currentMonth = (integer)substr($today, 5, 2);

    // Add missing months at the end to creation results
    while ($currentYear > $previousResult['year'] ||
      $currentMonth > $previousResult['month'])
    {
      $missingResult = array();
      $missingResult['total'] = $previousResult['total'];
      $missingResult['count'] = 0;

      if ($previousResult['month'] == 12)
      {
        $missingResult['year'] = $previousResult['year'] + 1;
        $missingResult['month'] = 1;
      }
      else
      {
        $missingResult['year'] = $previousResult['year'];
        $missingResult['month'] = $previousResult['month'] + 1;
      }

      $results[] = $missingResult;
      $previousResult = $missingResult;
    }

    return $results;
  }
}
