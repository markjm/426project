<?php
require_once 'php/orm_finance.php';

class Bill {
    private $id;
    private $title;
    private $summary;
    private $committee;
    private $published;
    private $code;
    private $cbo_link;
    private $pdf_link;
    private $finances;

    // This isn't a DB field like the others are - this is true when the Bill
    // is generated by a row on the PendingBills
    private $is_pending;

    private function __construct($id,
                                 $title,
                                 $summary,
                                 $committee,
                                 $published,
                                 $code,
                                 $cbo_link,
                                 $pdf_link,
                                 $pending) {
        $this->id = $id;
        $this->title = $title;
        $this->summary = $summary;
        $this->committee = $committee;
        $this->published = $published;
        $this->code = $code;
        $this->cbo_link = $cbo_link;
        $this->pdf_link = $pdf_link;
        $this->finances = array();

        $this->is_pending = $pending;
    }

    public function get_id() { return $this->id; }

    /*
     * Returns the Bill associated with the given ID, or null if no such Bill
     * exists.
     */
    public static function from_id($db, $id, $is_pending) {
        global $LOGGER;

        $bills_table = $is_pending ? "PendingBills" : "Bills";
        $query = $db->prepare("
            SELECT title, summary, committee, published, code, cbo_url, pdf_url
            FROM $bills_table
            WHERE id = ?
        ");

        $query->bind_param('i', $id);

        $query->execute();
        $query->bind_result($out_title,
                            $out_summary,
                            $out_committee,
                            $out_published,
                            $out_code,
                            $out_cbo_url,
                            $out_pdf_url);

        // There should only ever be 0 or 1 rows (id is a PK), so there's no
        // danger of throwing away multiple objects here
        $obj = null;
        while ($query->fetch()) {
            $LOGGER->debug(
                'Bill({id}) => ({title}, {summary}, {committee}, {published}, {code}, {cbo}, {pdf})',
                array('id' => $id,
                      'title' => $out_title,
                      'summary' => $out_summary,
                      'committee' => $out_committee,
                      'published' => $out_published,
                      'code' => $out_code,
                      'cbo' => $out_cbo_url,
                      'pdf' => $out_pdf_url));

            $obj = new Bill($id,
                            $out_title,
                            $out_summary,
                            $out_committee,
                            $out_published,
                            $out_code,
                            $out_cbo_url,
                            $out_pdf_url,
                            $is_pending);
        }

        $query->close();
        if ($obj == null) {
            $LOGGER->warning('No such Bill with id {id}', array('id' => $id));
            return null;
        }

        // Make sure that all the Finance records are also associated with the
        // Bill
        if (!$is_pending) {
            $query = $db->prepare('SELECT id FROM Finances WHERE bill = ?');
            $query->bind_param('i', $id);

            iter_stmt_result($query, function($row) use (&$db, &$obj) {
                array_push($obj->finances, Finance::from_id($db, $row['id']));
            });

            $LOGGER->debug(
                'Bill[{id}] had {entries} finance entries',
                array('id' => $id, 'entries' => count($obj->finances)));
        }

        return $obj;
    }

    /*
     * This is a general SQL loader, which is used for from_query and from_pending
     */
    public static function from_general_query($db, $order_param, $order_dir, $filter_params, $page_size, $is_pending) {
        global $LOGGER;
        $conditions = array();
        $params = array();
        $param_types = '';

        $sql_order_col = null;
        switch ($order_param) {
        case 'date':
            $sql_order_col = 'published';
            break;
        case 'committee':
            $sql_order_col = 'committee';
            break;
        case 'net':
            $sql_order_col = '(SELECT IFNULL(SUM(amount), 0) FROM Finances WHERE Finances.bill = Bills.id)';
            break;
        }

        // Since these are already 'asc' and 'desc', we can keep these as is
        $sql_order_dir = $order_dir;

        $LOGGER->debug('Order info: {col}, {dir}', array(
            'col' => $sql_order_col,
            'dir' => $sql_order_dir
        ));

        if (isset($filter_params['start'])) {
            $offset = $filter_params['start'];
        } else {
            $offset = 0;
            $LOGGER->debug('Executing without "start" param');
        }

        // Transfer each of the URL parameters into a form that the DB can 
        // understand, so that they can be part of the query
        foreach ($filter_params as $param_name => $param_value) {
            switch ($param_name) {
            case 'before':
                $LOGGER->debug('Condition: before {when}',
                               array('when' => $param_value));

                $before_ref = sqldatetime($param_value, false);
                array_push($conditions, 'published <= ?');
                $params[] =& $before_ref;
                $param_types = $param_types . 's';
                break;

            case 'after':
                $LOGGER->debug('Condition: after {when}',
                                array('when' => $param_value));

                $after_ref = sqldatetime($param_value, false);
                array_push($conditions, 'published >= ?');
                $params[] =& $after_ref;
                $param_types = $param_types . 's';
                break;

            case 'committee':
                $LOGGER->debug('Condition: by the {committee}',
                               array('committee' => $param_value));

                $committee_ref = $param_value;
                array_push($conditions, 'committee = ?');
                $params[] =& $committee_ref;
                $param_types = $param_types . 's';
                break;
            }
        }

        // To avoid having an empty WHERE clause, don't handle conditions if
        // none were provided
        if (count($params) > 0) {
            $full_sql = fmt_string(
                "SELECT id FROM {table} WHERE {conditions}
                ORDER BY {order_col} {order_dir}
                LIMIT {page_size} OFFSET {offset}",
                array(
                    'table' => $is_pending ? "PendingBills" : "Bills",
                    'conditions' => implode($conditions, ' AND '),
                    'order_col' => $sql_order_col,
                    'order_dir' => $sql_order_dir,
                    'page_size' => $page_size,
                    'offset' => $offset
                )
            );
        } else {
            $full_sql = fmt_string(
                'SELECT id FROM {table}
                 ORDER BY {order_col} {order_dir} 
                 LIMIT {page_size} OFFSET {offset}',
                array(
                    'table' => $is_pending ? "PendingBills" : "Bills",
                    'order_col' => $sql_order_col,
                    'order_dir' => $sql_order_dir,
                    'page_size' => $page_size,
                    'offset' => $offset
                )
            );
        }

        $LOGGER->debug('Executing: {query}', array('query' => $full_sql));
        $LOGGER->debug(
            'Params({param_types}): {params}',
            array('params' => print_r($params, true),
                  'param_types' => $param_types));

        $stmt = $db->prepare($full_sql);

        // mysqli_stmt::bind_param won't accept zero parameters, so we can only
        // do this if parameters were given
        if (count($params) > 0) {
            call_user_func_array(
                array($stmt, 'bind_param'),
                array_merge(array($param_types), $params));
        }

        $ids = array();
        iter_stmt_result($stmt, function($row) use (&$ids, &$results) {
            array_push($ids, (int)$row['id']);
        });

        $results = array();
        foreach ($ids as $id) {
            $LOGGER->debug('Creating Bill({id})', array('id' => $id));
            array_push($results, Bill::from_id($db, $id, $is_pending));
        }

        return $results;
    }

    /*
     * Returns an array of Bills that match a given set of conditions.
     *
     * Conditions should be an array of the form (though all of the parts are optional)
     *
     *     array('before' => DATE,
     *           'after' => DATE,
     *           'committee' => STRING,
     *           'start' => INTEGER)
     */
    public static function from_query($db, $order_param, $order_dir, $url_params, $page_size) {
        return Bill::from_general_query($db, $order_param, $order_dir, $url_params, $page_size, false);
    }

    /*
     * Gets an array of pending Bills. Note that conditions are largely
     * implicit - there is almost no filtering available on pending bills.
     */
    public static function from_pending($db, $start, $page_size) {
        return Bill::from_general_query($db, 'date', 'asc', array('start' => $start), $page_size, true);
    }

    /*
     * Constructs a Bill from an array - the bill may be either pending or not-pending - this
     * is detected on the basis of a 'finances' element.
     */
    public static function from_array($array) {
        $id = null;
        $title = $array['title'];
        $summary = $array['summary'];
        $committee = $array['committee'];
        $cbo_url = $array['cbo_url'];
        $pdf_url = $array['pdf_url'];
        $published = $array['published'];
        $code = $array['code'];
        $is_pending = !isset($array['finances']);

        if (!is_string($title)) return null;
        if (!is_string($summary)) return null;
        if (!is_string($committee)) return null;
        if (!is_string($cbo_url)) return null;
        if (!is_string($pdf_url)) return null;
        if (strtotime($published) === false) return null;
        if (!is_string($code)) return null;

        $bill = new Bill($id,
                         $title,
                         $summary,
                         $committee,
                         $published,
                         $code,
                         $cbo_url,
                         $pdf_url,
                         $is_pending);

        if (!$is_pending) {
            foreach ($array['finances'] as $finance) {
                $entry  = Finance::from_array($finance);
                if ($entry === null) return null;

                array_push($bill->finances, $entry);
            }
        }

        return $bill;
    }

    /*
     * Inserts this Bill into the database, if it isn't there already, and
     * returns the ID that it was inserted under. Also removes the
     * corresponding pending Bill from the database, if there is one.
     */
    public function finalize($db, $remove_from_pending=true) {
        global $LOGGER;

        if ($this->id !== null) return $this->id;

        // We have to do the insert on the Bill itself first, since its ID is
        // required by the Finance entries
        $LOGGER->debug('Adding bill to Bills table');
        $stmt = $db->prepare('
            INSERT INTO Bills(title, summary, committee, published, code, cbo_url, pdf_url)
            VALUES (?, ?, ?, ?, ?, ?, ?);
        ');

        $stmt->bind_param(
            'sssssss',
            $this->title,
            $this->summary,
            $this->committee,
            $this->published,
            $this->code,
            $this->cbo_link,
            $this->pdf_link);

        $stmt->execute();
        $stmt->close();

        $LOGGER->debug('Loading bill finance info');
        $bill_id = $db->insert_id;
        foreach ($this->finances as $finance) {
            $finance->insert($db, $bill_id);
        }

        $this->id = $bill_id;

        // Now, clean up the PendingBills entry. Note that we can use the CBO
        // URL here, since that is a UNIQUE constraint for Bills, both pending
        // and normal
        $LOGGER->debug('Removing bill from PendingBills table');
        $stmt = $db->prepare('
            DELETE FROM PendingBills WHERE cbo_url = ?
        ');

        $stmt->bind_param('s', $this->cbo_link);
        $stmt->execute();
        $stmt->close();

        return $this->id;
    }

    /*
     * Converts this object into an array, suitable for emission as JSON.
     */
    public function to_array() {
        $return_val = array(
            'title' => $this->title,
            'code' => $this->code,
            'summary' => $this->summary,
            'committee' => $this->committee,
            'published' => $this->published,
            'cbo_url' => $this->cbo_link,
            'pdf_url' => $this->pdf_link,
        );

        if (!$this->is_pending) {
            $return_val['finances'] = array();
            foreach ($this->finances as $finance) {
                array_push($return_val['finances'], $finance->to_array());
            }
        }

        return $return_val;
    }
}