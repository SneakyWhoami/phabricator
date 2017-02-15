<?php

class PhabricatorElasticSearchQueryBuilder {
  protected $name;
  protected $clauses = array();


  public function getClauses($termkey = null) {
    $clauses = $this->clauses;
    if ($termkey == null) {
      return $clauses;
    }
    if (isset($clauses[$termkey])) {
      return $clauses[$termkey];
    }
    return array();
  }

  public function clauseCount($clausekey) {
    if (isset($this->clauses[$clausekey])) {
      return count($this->clauses[$clausekey]);
    } else {
      return 0;
    }
  }

  public function exists($field) {
    return $this->addClause('filter', array(
      'exists' => array(
        'field' => $field,
      ),
    ));
  }

  public function terms($field, $values) {
    return $this->addClause('filter', array(
      'terms' => array(
        $field  => array_values($values),
      ),
    ));
  }

  public function must($clause) {
    return $this->addClause('must', $clause);
  }

  public function filter($clause) {
    return $this->addClause('filter', $clause);
  }

  public function should($clause) {
    return $this->addClause('should', $clause);
  }

  public function mustNot($clause) {
    return $this->addClause('must_not', $clause);
  }

  public function addClause($clause, $terms) {
    $this->clauses[$clause][] = $terms;
    return $this;
  }

  public function toArray() {
    $clauses = $this->getClauses();
    return $clauses;
    $cleaned = array();
    foreach ($clauses as $clause => $subclauses) {
      if (is_array($subclauses) && count($subclauses) == 1) {
        $cleaned[$clause] = array_shift($subclauses);
      } else {
        $cleaned[$clause] = $subclauses;
      }
    }
    return $cleaned;
  }

}
