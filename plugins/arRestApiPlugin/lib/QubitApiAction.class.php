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

class QubitAPIAction extends sfAction
{
  public function execute($request)
  {
    $view = sfView::NONE;

    try
    {
      $view = $this->process($request);
    }
    catch (QubitApi404Exception $e)
    {
      $this->response->setStatusCode(404, $e->getMessage());
    }
    catch (QubitApiNotAuthorizedException $e)
    {
      $this->response->setStatusCode(401);
    }
    catch (QubitApiForbiddenException $e)
    {
      $this->response->setStatusCode(403, $e->getMessage());
    }
    catch (QubitApiBadRequestException $e)
    {
      $this->response->setStatusCode(400, $e->getMessage());
    }
    catch (Exception $e)
    {
      $this->response->setStatusCode(500);

      throw $e;
    }

    return $view;
  }

  public function process($request)
  {
    $method = strtoupper($request->getMethod());
    if (!method_exists($this, $method))
    {
      return $this->forward404();
    }

    // Define function callable
    $fnCallable = array($this, $method);
    $fnParamaters = array($request);

    // Modern frameworks support application/json, Symfony1 is too old :)
    // AngularJS doesn't use application/x-www-form-urlencoded
    if (('PUT' == $method || 'POST' == $method) && 'application/json' == $request->getContentType())
    {
      $fnParamaters[] = json_decode($request->getContent());
    }

    // Load Qubit helper before calling it
    ProjectConfiguration::getActive()->loadHelpers(array('Asset', 'Qubit'));

    $result = call_user_func_array($fnCallable, $fnParamaters);

    return $this->renderData($result);
  }

  public function renderData($data)
  {
    if ($data === 'CSV')
    {
      return sfView::NONE;
    }

    if ($data === sfView::NONE)
    {
      $this->response->setHeaderOnly(true);

      $this->response->setStatusCode(204);

      return sfView::NONE;
    }

    $options = 0;
    if ($this->context->getConfiguration()->isDebug() && defined('JSON_PRETTY_PRINT'))
    {
      $options |= JSON_PRETTY_PRINT;
    }

    $this->response->setHttpHeader('Content-Type', 'application/json; charset=utf-8');

    return $this->renderText(json_encode($data, $options));
  }

  /**
   * Filter out selected aggregations.
   */
  protected function filterEsQuery($name, $field, \Elastica\Query\BoolQuery &$queryBool, $operator = 'AND', array $options = array())
  {
    if (!isset($this->request->$name))
    {
      return;
    }

    // Ensure type array
    $this->request->$name = (array) $this->request->$name;

    // Check type of the elements in the array
    if (!$options['noInteger'])
    {
      foreach ($this->request->$name as $item)
      {
        if (true !== ctype_digit($item))
        {
          return;
        }
      }
    }

    $query = new \Elastica\Query\Terms;
    $query->setTerms($field, $this->request->$name);

    switch (strtolower($operator))
    {
      case 'or':
      case 'should':
        $queryBool->addShould($query);

        break;

      case 'nor':
      case 'not':
      case 'must_not':
        $queryBool->addMustNot($query);

        break;

      case 'and':
      case 'must':
      default:
        $queryBool->addMust($query);
    }
  }

  protected function filterEsRangeQuery($from, $to, $field, \Elastica\Query\BoolQuery &$queryBool, array $options = array())
  {
    if (!isset($this->request->$from) && !isset($this->request->$to))
    {
      return;
    }

    $range = array();

    if (isset($this->request->$from) && ctype_digit($this->request->$from))
    {
      $range['gte'] = $this->request->$from;
    }

    if (isset($this->request->$to) && ctype_digit($this->request->$to))
    {
      $range['lte'] = $this->request->$to;
    }

    $query = new \Elastica\Query\Range($field, $range);

    $queryBool->addMust($query);
  }

  protected function prepareEsPagination(\Elastica\Query &$query, $limit = null)
  {
    $limit = empty($limit) ? sfConfig::get('app_hits_per_page', 10) : $limit;
    $limit = $this->request->getGetParameter('limit', $limit);
    if (!ctype_digit($limit) || $limit > 100)
    {
      $limit = 100;
    }

    $skip = 0;
    if (isset($this->request->skip) && ctype_digit($this->request->skip))
    {
      $skip = $this->request->skip;
    }

    // Avoid pagination over 10,000 records
    if ((int)$skip + (int)$limit > 10000)
    {
      // Return 400 response with error message
      $message = $this->context->i18n->__("Pagination limit reached. To avoid using vast amounts of memory, Binder limits pagination to 10,000 records. Please, narrow down your results.");
      throw new QubitApiBadRequestException($message);
    }

    $query->setFrom($skip);
    $query->setSize($limit);
  }

  protected function prepareEsSorting(\Elastica\Query &$query, $fields = array(), $default = array())
  {
    // Stop if preferred option is not set or $fields empty
    if (empty($fields) || !isset($this->request->sort))
    {
      if (!empty($default))
      {
        $query->setSort($default);
      }

      return;
    }

    // Stop if the preferred option can't be found
    if (false === array_search($this->request->sort, array_keys($fields)))
    {
      return;
    }

    $sortDirection = 'asc';
    if (isset($this->request->sort_direction) && 'desc' == $this->request->sort_direction)
    {
      $sortDirection = 'desc';
    }

    // TODO: allow $request->sort to be multi-value
    $query->setSort(array($fields[$this->request->sort] => $sortDirection));
  }

  protected function buildEsAgg($aggrType, $name, $field, array $options = array())
  {
    $className = '\\Elastica\\Aggregation\\'.$aggrType;
    $aggr = new $className($name);

    if ($aggrType == 'Terms')
    {
      $setSize = (isset($options['setSize'])) ? $options['setSize'] : 10;
      $aggr->setSize($setSize);
    }

    if (isset($field))
    {
      $aggr->setField($field);
    }
    else if (isset($options['script']))
    {
      $script = new \Elastica\Script\Script($options['script']);
      $aggr->setScript($script);
    }

    if (isset($options['interval']))
    {
      $aggr->setInterval($options['interval']);
    }

    if (isset($options['params']))
    {
      foreach ($options['params'] as $param)
      {
        $aggr->setParam($param['name'], $param['value']);
      }
    }

    switch ($aggrType)
    {
      case 'Range':
      case 'DateRange':
        foreach ($options['ranges'] as $range)
        {
          $aggr->addRange($range['from'], $range['to'], $range['key']);
        }

        break;
    }

    return $aggr;
  }

  protected function formatAggs(&$aggs)
  {
    foreach ($aggs as $name => &$agg)
    {
      if (!isset($agg['buckets']) || 0 == count($agg['buckets']))
      {
        continue;
      }

      foreach ($agg['buckets'] as &$bucket)
      {
        if (method_exists($this, 'getAggLabel') && null !== $label = $this->getAggLabel($name, $bucket['key']))
        {
          $bucket['label'] = $label;
        }
      }
    }
  }

  protected function addItemToArray(&$array, $key, $value)
  {
    if (empty($value))
    {
      return;
    }

    $array[$key] = $value;
  }

  protected function getAipsOverview($artworkId = null)
  {
    $query = new \Elastica\Query;

    // Filter by artwork or get all
    if (isset($artworkId))
    {
      $query->setQuery(new \Elastica\Query\Term(array('partOf.id' => $artworkId)));
    }
    else
    {
      $query->setQuery(new \Elastica\Query\MatchAll);
    }

    // We don't need details, just aggregation results
    $query->setSize(0);

    // Create nested aggregation to get total size by type
    // including those missing type, as type.id = 0
    $aggOptions = array(
      'params' => array(
        array(
          'name' => 'missing',
          'value' => 0
        )
      )
    );
    $agg = $this->buildEsAgg('Terms', 'type', 'type.id', $aggOptions);
    $agg->addAggregation($this->buildEsAgg('Sum', 'size', 'sizeOnDisk'));
    $query->addAggregation($agg);

    $resultSet = QubitSearch::getInstance()->index->getType('QubitAip')->search($query);
    $aggs = $resultSet->getAggregations();

    $results = array('total' => array('size' => 0, 'count' => 0));
    foreach ($aggs['type']['buckets'] as $bucket)
    {
      // Rename missing type key
      if ($bucket['key'] == 0)
      {
        $bucket['key'] = 'unclassified';
      }

      // Add values per type
      $results[$bucket['key']] = array(
        'size' => $bucket['size']['value'],
        'count' => $bucket['doc_count']
      );

      // Calculate totals
      $results['total']['size'] += $bucket['size']['value'];
      $results['total']['count'] += $bucket['doc_count'];
    }

    return $results;
  }
}
