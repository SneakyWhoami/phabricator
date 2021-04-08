<?php

class ProjectMetrics {
  protected $tasks = [];

  protected $request;
  protected $project;
  protected $metrics;

  public function __construct($request, $project) {
    $this->request = $request;
    $this->project = $project;
  }

  public function getMetrics() {
    return $this->metrics;
  }

  public function getMetric($name) {
    if (!isset($this->metrics[$name])) {
      return null;
    }
    return $this->metrics[$name];
  }

  protected function getViewer() {
    return $this->request->getViewer();
  }

  public function getProjectPHID() {
    return $this->project->getPHID();
  }

  public function getProject() {
    return $this->project;
  }

  public function getProjectColumns($status=null) {
    if (!isset($status)){
      $status = PhabricatorProjectColumn::STATUS_ACTIVE;
    }
    $columns = id(new PhabricatorProjectColumnQuery())
        ->setViewer($this->getViewer())
        ->withProjectPHIDs([$this->getProjectPHID()])
        ->withStatuses([$status])
        ->execute();

    return $columns;
  }


  public function getBoardContainerPHIDs() {
    $project = $this->getProject();
    $viewer = $this->getViewer();

    $container_phids = array($project->getPHID());
    if ($project->getHasSubprojects() || $project->getHasMilestones()) {
      $descendants = id(new PhabricatorProjectQuery())
        ->setViewer($viewer)
        ->withAncestorProjectPHIDs($container_phids)
        ->execute();
      foreach ($descendants as $descendant) {
        $container_phids[] = $descendant->getPHID();
      }
    }

    return $container_phids;
  }

  public function computeMetrics() {
    $container_phids = $this->getBoardContainerPHIDs();
    $project_phid = $this->getProjectPHID();
    $columns = $this->getProjectColumns();
    $columns = msort($columns, 'getSequence');
    $tasks_by_column = [];

    // initialize structure for workboard columns
    foreach($columns as $col) {
      $phid = $col->getPHID();
      $proxy = $col->getProxy();

      if ($proxy) {
        $proxyphid = $proxy->getPHID();
        $col_search_url = "/maniphest/?tags=$proxyphid#R";
      } else {
        $col_search_url = "/maniphest/?columns=$phid#R";
      }
      $tasks_by_column[$phid] = [
        "name"  => $col->getDisplayName(),
        "href"  => $col_search_url,
        "tasks" => [],
        "ages"  => []
      ];
    }

    // start and end date
    $start = $this->request->getInt('startdate');
    $end = $this->request->getInt('enddate');

    // completed tasks
    $query = new ManiphestTaskQuery();
    $query->setViewer($this->getViewer())
      ->withEdgeLogicPHIDs(
        PhabricatorProjectObjectHasProjectEdgeType::EDGECONST,
        PhabricatorQueryConstraint::OPERATOR_ANCESTOR,
        array($container_phids))
      ->withStatuses([ManiphestTaskStatus::STATUS_CLOSED_RESOLVED])
      ->withClosedEpochBetween($start, $end);
    $completed = $query->execute();
    $this->metrics['completed'] = pht(' %d ', count($completed));

    // open tasks
    $query = new ManiphestTaskQuery();
    $query->setViewer($this->getViewer())
      ->withEdgeLogicPHIDs(
        PhabricatorProjectObjectHasProjectEdgeType::EDGECONST,
        PhabricatorQueryConstraint::OPERATOR_ANCESTOR,
        array($container_phids))
      ->withStatuses(ManiphestTaskStatus::getOpenStatusConstants());
    $tasks = $query->execute();
    if (empty($tasks)){
      return;
    }
    $task_phids = mpull($tasks, 'getPHID');
    $task_owner_phids = mpull($tasks, 'getOwnerPHID');
    $owner_tasks = [];
    $unassigned = 0;

    // find the task count per owner
    foreach($task_owner_phids as $task=>$owner) {
      if (!$owner) {
        $unassigned++;
        continue;
      }
      if (!isset($owner_tasks[$owner])) {
        $owner_tasks[$owner] = 1;
      } else {
        $owner_tasks[$owner] += 1;
      }
    }
    $this->metrics['tasks_by_owner'] = $owner_tasks;
    $this->metrics['unassigned_count'] = $unassigned;
    // get workboard columns
    $engine = id(new PhabricatorBoardLayoutEngine())
      ->setViewer($this->getViewer())
      ->setBoardPHIDs([$this->getProjectPHID()])
      ->setObjectPHIDs($task_phids)
      ->executeLayout();

    $now = new DateTime();
    $task_ages = [];
    $due_dates = self::getTaskDueByPHID($task_phids);
    $this->metrics['overdue'] = [];
    $this->metrics['open_task_count'] = count($tasks);
    // compute ages and columns for each task
    foreach($tasks as $task){
      $phid = $task->getPHID();
      $date_created = $task->getDateCreated();
      $ago = new DateTime();
      $ago->setTimestamp($date_created);
      $diff = $now->diff($ago);
      $task_ages[] = $diff->days;
      if (isset($due_dates[$phid])) {
        $due = $due_dates[$phid];
        if ($due <= $now->getTimestamp()) {
          $this->metrics['overdue'][$phid]=$due;
        }
      }

      $task_columns = $engine->getObjectColumns(
        $project_phid,
        $phid);
      // count tasks in each column
      foreach($task_columns as $col) {
        $col_phid = $col->getPHID();
        $tasks_by_column[$col_phid]['tasks'][] = $task;
        $tasks_by_column[$col_phid]['ages'][] = $diff->days;
      }
    }
    $max_age = max($task_ages);
    $this->metrics['max_age'] = $max_age;
    foreach ($tasks_by_column as $col_phid=>$col) {
      $tasks_by_column[$col_phid]['stats'] =
        $this->computeAgeStats($col['ages']);
    }

    $this->metrics['columns'] = $tasks_by_column;
    $this->metrics['age'] =
      $this->computeAgeStats($task_ages);
    $this->metrics['histogram'] =
      $this->makeAgeHistogram($task_ages);
  }

  public function makeHistogramBuckets($count, $interval) {
    $res = [];
    $i=0;
    while( count($res) < $count) {
      $i += $interval;
      $res[$i] = 0;
    }

    return $res;
  }
  public function makeAgeHistogram($ages, $buckets=null) {
    if ($buckets == null) {
      $buckets = $this->makeHistogramBuckets(6, 7);
    }

    $max_age = max($ages);
    $buckets[$max_age] = 0;

    sort($ages);
    reset($buckets);
    $current = key($buckets);
    foreach($ages as $age) {
      if ($age <= $current) {
        $buckets[$current]++;
      } else {
        $next = next($buckets);
        if ($next === false) {
          break;
        } else {
          $current = key($buckets);
        }
      }
    }
    return $buckets;
  }

  public function computeAgeStats($task_ages) {
    if (empty($task_ages)) {
      return [
        'count' => 0,
        'min'=> 0,
        'mean'=> 0,
        'median'=> 0,
        'max'=> 0
      ];
    }
    sort($task_ages, SORT_NUMERIC);
    $original_count = count($task_ages);
    $task_ages = array_values(array_unique($task_ages));
    $count = count($task_ages);
    $mean_age = array_sum($task_ages) / $count;
    $mid = (int)floor($count / 2);
    if ($count > 4) {
      if ($count & 1) { //odd
        $median_age = $task_ages[$mid];
      } else { // even
        $median_age = ($task_ages[$mid] + $task_ages[$mid+1]) / 2;
      }
    } else {
      $median_age = $mean_age;
    }
    $max_age = $task_ages[$count-1];
    $min_age = $task_ages[0];

    return [
      'count' => $original_count,
      'min'=> round($min_age),
      'mean'=> round($mean_age),
      'median'=> round($median_age),
      'max'=> round($max_age)
    ];

  }

  public static function getTaskDueByPHID(array $phids) {
    if (empty($phids)){
      return [];
    }
    $fieldIndex = PhabricatorHash::digestForIndex(
      'std:maniphest:deadline.due');
    $storage = new ManiphestCustomFieldStorage();
    $conn = $storage->establishConnection('r');

    $rows = queryfx_all(
      $conn,
      'SELECT objectPHID, fieldValue FROM %T
        WHERE fieldIndex=%s AND objectPHID in (%Ls)',
      $storage->getTableName(),
      $fieldIndex,
      $phids);

    $res = [];
    foreach($rows as $row) {
      $res[$row['objectPHID']] = (int) $row['fieldValue'];
    }
    return $res;
  }

  public function getColumnTransactionsForProject($projectPHIDs) {
    $storage = new ManiphestTransaction();
    $conn = $storage->establishConnection('r');
    $rows = queryfx_all(
      $conn,
      'SELECT
        trns.objectPHID,
        trns.authorPHID,
        JSON_VALUE(trns.newValue, "$[0].boardPHID") as projectPHID,
        JSON_VALUE(trns.newValue, "$[0].columnPHID") as toColumnPHID,
        JSON_VALUE(trns.newValue, "$[0].fromColumnPHIDs.*") as fromColumnPHID
      FROM %T trns
      WHERE
        transactionType="core:columns"
      AND
        JSON_VALUE(trns.newValue, "$[0].boardPHID") IN (%Ls)
      GROUP BY
        objectPHID
      ORDER BY
        dateModified',
      $storage->getTableName(),
      $projectPHIDs);
    return $rows;
}



}
