<?php

require_once 'modules/billing/models/Invoice.php';
require_once 'modules/clients/models/User.php';

// TEMPORARY DEBUG: set to false (or delete the debug lines) once the report works.
if (!defined('AR_AGING_DEBUG')) {
    define('AR_AGING_DEBUG', true);
}
if (AR_AGING_DEBUG) {
    @error_log('AR_Aging_V5 file loaded. URI=' . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : ''));
}

/**
 * AR Aging report for ClientExec
 *
 * Views:
 *   - summary (default): one row per customer, one column per aging bucket
 *   - detail           : one row per unpaid invoice (optionally for one customer)
 *
 * URL params (all optional):
 *   view=summary|detail
 *   asof=YYYY-MM-DD            (default: today)
 *   buckets=30,60,90           (upper bounds of the buckets, default 30,60,90)
 *   customerid=123             (detail view / summary filter)
 *   minamount=1000000          (minimum balance per invoice)
 *   min_days=15                (detail view: only invoices at least N days overdue)
 *   page=1&results=250         (detail view pagination)
 *   download=1                 (CSV download of the current view)
 *
 * Install: put this file in  plugins/reports/AR_Aging/AR_Aging.php
 * Open it from the Reports menu (it will be listed as "AR Aging").
 */
class AR_Aging extends Report
{
    private $lang;
    protected $featureSet = 'support';

    /** Invoice statuses that count as "unpaid" (0 = pending). Add partial-paid status if your version has one. */
    const UNPAID_STATUSES = '0';

    /** Default bucket upper bounds in days */
    const DEFAULT_BUCKETS = '30,60,90';

    private $dueCol;      // column used as due date
    private $balanceCol;  // column used as remaining balance
    private $asOf;        // Y-m-d
    private $bounds = []; // e.g. [30, 60, 90]

    public function __construct($user = null, $customer = null)
    {
        $this->lang = lang('AR Aging V5');
        parent::__construct($user, $customer);

    }

    /* ------------------------------------------------------------------ */
    /*  Setup helpers                                                      */
    /* ------------------------------------------------------------------ */

    /** Use real due date / balance columns if the invoice table has them, otherwise fall back. */
    private function detectColumns()
    {
        $cols = [];
        $res = $this->db->query('SHOW COLUMNS FROM invoice');
        while ($r = $res->fetch()) {
            $cols[] = $r['Field'];
        }

        $this->dueCol = 'billdate';
        foreach (['duedate', 'datedue', 'date_due'] as $c) {
            if (in_array($c, $cols, true)) {
                $this->dueCol = $c;
                break;
            }
        }

        $this->balanceCol = in_array('balance_due', $cols, true) ? 'balance_due' : 'amount';
    }

    private function readAsOf()
    {
        $raw = isset($_REQUEST['ar_asof']) ? trim($_REQUEST['ar_asof']) : '';

        if ($raw !== '') {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
                if (checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                    return $raw;
                }
            } elseif (strpos($raw, '/') !== false) {
                $a = explode('/', $raw);
                if (count($a) === 3) {
                    if ($this->settings->get('Date Format') == 'm/d/Y') {
                        list($mo, $d, $y) = $a;
                    } else {
                        list($d, $mo, $y) = $a;
                    }
                    if (checkdate((int)$mo, (int)$d, (int)$y)) {
                        return sprintf('%04d-%02d-%02d', (int)$y, (int)$mo, (int)$d);
                    }
                }
            }
        }
        return date('Y-m-d');
    }

    private function readBuckets()
    {
        $raw = isset($_REQUEST['ar_buckets']) ? $_REQUEST['ar_buckets'] : self::DEFAULT_BUCKETS;
        $b = [];
        foreach (explode(',', $raw) as $n) {
            $n = intval($n);
            if ($n > 0) {
                $b[] = $n;
            }
        }
        $b = array_values(array_unique($b));
        sort($b);
        if (empty($b) || count($b) > 8) {
            $b = array_map('intval', explode(',', self::DEFAULT_BUCKETS));
        }
        return $b;
    }

    /** Bucket column labels: Current, 1-30, 31-60, 61-90, 90+ */
    private function bucketLabels()
    {
        $labels = ['Current (not due)'];
        $prev = 0;
        foreach ($this->bounds as $b) {
            $labels[] = ($prev + 1) . '-' . $b . ' days';
            $prev = $b;
        }
        $labels[] = $prev . '+ days';
        return $labels;
    }

    /* ------------------------------------------------------------------ */
    /*  SQL builders                                                       */
    /* ------------------------------------------------------------------ */

    private function daysExpr()
    {
        return "DATEDIFF('{$this->asOf}', DATE(invoice.{$this->dueCol}))";
    }

    private function where()
    {
        $where = [
            'invoice.status IN (' . self::UNPAID_STATUSES . ')',
            "invoice.{$this->balanceCol} > 0",
            "DATE(invoice.billdate) <= '{$this->asOf}'",
        ];
        if (!empty($_REQUEST['ar_customerid'])) {
            $where[] = 'invoice.customerid = ' . intval($_REQUEST['ar_customerid']);
        }
        if (!empty($_REQUEST['ar_minamount'])) {
            $where[] = "invoice.{$this->balanceCol} >= " . floatval($_REQUEST['ar_minamount']);
        }
        if (!empty($_REQUEST['ar_min_days'])) {
            $where[] = $this->daysExpr() . ' >= ' . intval($_REQUEST['ar_min_days']);
        }
        return implode(' AND ', $where);
    }

    /** SUM(CASE ...) for each bucket, aliased b0 .. bN */
    private function bucketSums()
    {
        $d   = $this->daysExpr();
        $bal = "invoice.{$this->balanceCol}";
        $sql = ["SUM(CASE WHEN $d <= 0 THEN $bal ELSE 0 END) AS b0"];

        $prev = 0;
        $i = 1;
        foreach ($this->bounds as $b) {
            $sql[] = "SUM(CASE WHEN $d > $prev AND $d <= $b THEN $bal ELSE 0 END) AS b$i";
            $prev = $b;
            $i++;
        }
        $sql[] = "SUM(CASE WHEN $d > $prev THEN $bal ELSE 0 END) AS b$i";
        return implode(",\n                ", $sql);
    }

    /** CASE expression returning the bucket label for one invoice */
    private function bucketCase()
    {
        $d = $this->daysExpr();
        $labels = $this->bucketLabels();
        $sql = "CASE WHEN $d <= 0 THEN '" . $labels[0] . "'";
        $prev = 0;
        $i = 1;
        foreach ($this->bounds as $b) {
            $sql .= " WHEN $d <= $b THEN '" . $labels[$i] . "'";
            $i++;
        }
        $sql .= " ELSE '" . $labels[$i] . "' END";
        return $sql;
    }

    /* ------------------------------------------------------------------ */
    /*  Data                                                               */
    /* ------------------------------------------------------------------ */

    /** Summary rows (raw numbers) + grand total row. Returns [rows, totals]. */
    private function getSummaryData()
    {
        $where = $this->where();
        $bal   = "invoice.{$this->balanceCol}";
        $nb    = count($this->bounds) + 2; // number of bucket columns

        $sql = "SELECT invoice.customerid,
                       users.firstname, users.lastname, users.organization,
                       COUNT(*) AS cnt,
                       SUM($bal) AS total,
                       {$this->bucketSums()}
                FROM invoice
                LEFT JOIN users ON users.id = invoice.customerid
                WHERE $where
                GROUP BY invoice.customerid, users.firstname, users.lastname, users.organization
                ORDER BY (SUM($bal) - SUM(CASE WHEN {$this->daysExpr()} <= 0 THEN $bal ELSE 0 END)) DESC";

        $res    = $this->db->query($sql);
        $rows   = [];
        $totals = array_fill(0, $nb, 0.0);
        $totalCnt = 0;
        $totalAll = 0.0;

        while ($r = $res->fetch()) {
            $name = trim($r['firstname'] . ' ' . $r['lastname']);
            if (!empty($r['organization'])) {
                $name .= ' - ' . $r['organization'];
            }
            $row = [
                'customerid' => (int)$r['customerid'],
                'name'       => $name,
                'cnt'        => (int)$r['cnt'],
                'buckets'    => [],
                'total'      => (float)$r['total'],
            ];
            for ($i = 0; $i < $nb; $i++) {
                $v = (float)$r['b' . $i];
                $row['buckets'][] = $v;
                $totals[$i] += $v;
            }
            $totalCnt += $row['cnt'];
            $totalAll += $row['total'];
            $rows[] = $row;
        }

        return [$rows, ['cnt' => $totalCnt, 'buckets' => $totals, 'total' => $totalAll]];
    }

    /** Detail rows (raw values). Returns ['data' => rows, 'total' => count, 'sum' => amount]. */
    private function getDetailData($limit, $offset)
    {
        $where = $this->where();
        $bal   = "invoice.{$this->balanceCol}";
        $limit  = intval($limit);
        $offset = intval($offset);

        $row = $this->db->query("SELECT COUNT(*) AS c, SUM($bal) AS s FROM invoice WHERE $where")->fetch();
        $total = (int)$row['c'];
        $sum   = (float)$row['s'];

        $sql = "SELECT invoice.id, invoice.customerid, invoice.billdate,
                       invoice.{$this->dueCol} AS duedate,
                       $bal AS balance,
                       {$this->daysExpr()} AS days_overdue,
                       {$this->bucketCase()} AS bucket,
                       users.firstname, users.lastname, users.organization
                FROM invoice
                LEFT JOIN users ON users.id = invoice.customerid
                WHERE $where
                ORDER BY days_overdue DESC, invoice.id ASC
                LIMIT $offset, $limit";

        $res  = $this->db->query($sql);
        $data = [];
        while ($r = $res->fetch()) {
            $name = trim($r['firstname'] . ' ' . $r['lastname']);
            if (!empty($r['organization'])) {
                $name .= ' - ' . $r['organization'];
            }
            $data[] = [
                'id'         => (int)$r['id'],
                'customerid' => (int)$r['customerid'],
                'name'       => $name,
                'billdate'   => substr($r['billdate'], 0, 10),
                'duedate'    => substr($r['duedate'], 0, 10),
                'jalali'     => $this->toPersianDate($r['duedate']),
                'days'       => max(0, (int)$r['days_overdue']),
                'bucket'     => $r['bucket'],
                'balance'    => (float)$r['balance'],
            ];
        }
        return ['data' => $data, 'total' => $total, 'sum' => $sum];
    }

    /**
     * Gregorian date -> Jalali (Persian) date, yyyy-mm-dd.
     * Uses PHP's intl extension if present, otherwise a pure-PHP conversion,
     * so the report never crashes on servers without intl.
     */
    public function toPersianDate($date = 'now')
    {
        $ts = strtotime($date);
        if ($ts === false) {
            return '';
        }

        if (class_exists('IntlDateFormatter')) {
            try {
                $formatter = new IntlDateFormatter(
                    'fa_IR@calendar=persian',
                    IntlDateFormatter::NONE,
                    IntlDateFormatter::NONE,
                    'Asia/Tehran',
                    IntlDateFormatter::TRADITIONAL,
                    'yyyy-MM-dd'
                );
                $out = $formatter->format($ts);
                if ($out !== false) {
                    return strtr($out, [
                        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
                        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                    ]);
                }
            } catch (\Throwable $e) {
                // fall through to the manual conversion
            }
        }

        list($jy, $jm, $jd) = $this->gregorianToJalali((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
        return sprintf('%04d-%02d-%02d', $jy, $jm, $jd);
    }

    private function gregorianToJalali($gy, $gm, $gd)
    {
        $gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = 355666 + (365 * $gy) + (int)(($gy2 + 3) / 4) - (int)(($gy2 + 99) / 100)
            + (int)(($gy2 + 399) / 400) + $gd + $gdm[$gm - 1];
        $jy = -1595 + (33 * (int)($days / 12053));
        $days %= 12053;
        $jy += 4 * (int)($days / 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += (int)(($days - 1) / 365);
            $days = ($days - 1) % 365;
        }
        if ($days < 186) {
            $jm = 1 + (int)($days / 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + (int)(($days - 186) / 30);
            $jd = 1 + (($days - 186) % 30);
        }
        return [$jy, $jm, $jd];
    }

    /* ------------------------------------------------------------------ */
    /*  URLs & HTML helpers                                                */
    /* ------------------------------------------------------------------ */

    private function url(array $override = [])
    {
        $params = [
            'fuse'       => 'reports',
            'view'       => 'viewreport',
            'controller' => 'index',
            'report'     => $this->reportName(),
            'type'       => $this->reportType(),
            'ar_mode'    => $this->currentMode(), // 'summary' or 'detail'
            'ar_asof'    => $this->asOf,
            'ar_buckets' => implode(',', $this->bounds),
        ];
        foreach (['ar_customerid', 'ar_minamount', 'ar_min_days', 'results'] as $k) {
            if (!empty($_REQUEST[$k])) {
                $params[$k] = $_REQUEST[$k];
            }
        }
        if (!empty($_REQUEST['sessionHash']) && preg_match('/^[A-Za-z0-9]+$/', $_REQUEST['sessionHash'])) {
            $params['sessionHash'] = $_REQUEST['sessionHash'];
        }
        foreach ($override as $k => $v) {
            if ($v === null) {
                unset($params[$k]);
            } else {
                $params[$k] = $v;
            }
        }
        return 'index.php?' . http_build_query($params);
    }

    /**
     * NOTE: ClientExec already uses "view=viewreport" for its own routing, so the
     * summary/detail switch is passed as "mode" instead of "view".
     */
    /** "AR_Aging" -> "AR Aging" (ClientExec links use the spaced name, e.g. report=Revenue+By+State) */
    private function reportName()
    {
        return str_replace('_', ' ', get_class($this));
    }

    /** Report category as used by the menu link (falls back to Income). */
    private function reportType()
    {
        return (!empty($_REQUEST['type']) && preg_match('/^[A-Za-z0-9_ ]+$/', $_REQUEST['type'])) ? $_REQUEST['type'] : 'Income';
    }

    private function currentMode()
    {
        return (isset($_REQUEST['ar_mode']) && $_REQUEST['ar_mode'] === 'detail') ? 'detail' : 'summary';
    }

    private function h($s)
    {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }

    private function money($n)
    {
        return number_format($n, 0);
    }

    private function invoiceLink($id, $customerId)
    {
        return "<a href='index.php?fuse=billing&controller=invoice&view=invoice&profile=1&frmClientID=$customerId&invoiceid=$id' target='_blank'>#$id</a>";
    }

    private function customerLink($customerId, $name)
    {
        return "<a href='index.php?fuse=clients&controller=userprofile&view=profilecontact&frmClientID=$customerId' target='_blank'>"
            . $this->h($name) . " ($customerId)</a>";
    }

    private function renderFilters()
    {
        $mode = $this->currentMode();
        $fmt  = $this->settings->get('Date Format');
        $v = function ($k) {
            return isset($_REQUEST['ar_' . $k]) ? $this->h($_REQUEST['ar_' . $k]) : '';
        };

        $base = 'index.php?fuse=reports&view=viewreport&controller=index&report='
            . urlencode($this->reportName()) . '&type=' . urlencode($this->reportType());

        $f  = '<form id="report" method="GET">';
        $f .= '<div style="text-align:center">';
        $f .= '<small style="opacity:.6">AR Aging V5 (new copy)</small><br>';

        $f .= 'View: <select class="normalSelect2 w-20" name="ar_mode" id="ar_mode">'
            . '<option value="summary"' . ($mode === 'summary' ? ' selected' : '') . '>Summary by customer</option>'
            . '<option value="detail"' . ($mode === 'detail' ? ' selected' : '') . '>Invoice detail</option>'
            . '</select>&nbsp;&nbsp;&nbsp;';

        $f .= 'As of: <input class="datepicker" type="text" name="ar_asof" id="ar_asof" value=\''
            . CE_Lib::db_to_form($this->asOf, $fmt, '/') . '\' />&nbsp;&nbsp;&nbsp;';

        $f .= 'Buckets (days): <input type="text" name="ar_buckets" id="ar_buckets" size="12" value="'
            . $this->h(implode(',', $this->bounds)) . '" />&nbsp;&nbsp;&nbsp;';

        $f .= 'Customer ID: <input type="text" name="ar_customerid" id="ar_customerid" size="6" value="' . $v('customerid') . '" />&nbsp;&nbsp;&nbsp;';
        $f .= 'Min amount: <input type="text" name="ar_minamount" id="ar_minamount" size="10" value="' . $v('minamount') . '" />&nbsp;&nbsp;&nbsp;';
        $f .= 'Min days overdue: <input type="text" name="ar_min_days" id="ar_min_days" size="4" value="' . $v('min_days') . '" />&nbsp;&nbsp;&nbsp;';

        $f .= '<input type=button name=search class="cm-btns orange" value="Search" onclick="ChangeTable(0);">&nbsp;&nbsp;&nbsp;';
        $f .= '<button class="cm-btns orange" type="button" onclick="ChangeTable(1);">Download .csv</button>';

        $f .= '</div></form><br/>';

        $f .= '<script type="text/javascript">'
            . 'function ChangeTable(download){'
            . 'var g=function(id){var e=document.getElementById(id);return e?e.value:"";};'
            . 'var u=' . json_encode($base) . ';'
            . 'u+="&ar_mode="+encodeURIComponent(g("ar_mode"));'
            . 'u+="&ar_asof="+encodeURIComponent(g("ar_asof"));'
            . 'u+="&ar_buckets="+encodeURIComponent(g("ar_buckets"));'
            . 'u+="&ar_customerid="+encodeURIComponent(g("ar_customerid"));'
            . 'u+="&ar_minamount="+encodeURIComponent(g("ar_minamount"));'
            . 'u+="&ar_min_days="+encodeURIComponent(g("ar_min_days"));'
            . 'u+="&download="+download;'
            . 'var m=window.location.search.match(/sessionHash=([A-Za-z0-9]+)/);'
            . 'if(m){u+="&sessionHash="+m[1];}'
            . 'location.href=u;'
            . '}'
            . '</script>';

        echo $f;
    }

    /* ------------------------------------------------------------------ */
    /*  CSV                                                                */
    /* ------------------------------------------------------------------ */

    /** CSV through ClientExec's built-in download(), as the other reports do. */
    public function generate()
    {
        $rows = [];

        if ($this->currentMode() === 'detail') {
            $labels = ['Invoice ID', 'Customer ID', 'Customer', 'Bill Date', 'Due Date', 'Due Date (Jalali)', 'Days Overdue', 'Bucket', 'Balance (Rls)'];
            $res = $this->getDetailData(1000000, 0);
            foreach ($res['data'] as $r) {
                $rows[] = [$r['id'], $r['customerid'], $r['name'], $r['billdate'], $r['duedate'], $r['jalali'], $r['days'], $r['bucket'], $r['balance']];
            }
            $filename = 'AR Aging detail ' . $this->asOf . '.csv';
        } else {
            $labels = array_merge(['Customer ID', 'Customer', 'Invoices'], $this->bucketLabels(), ['Total (Rls)']);
            list($data, $totals) = $this->getSummaryData();
            foreach ($data as $r) {
                $rows[] = array_merge([$r['customerid'], $r['name'], $r['cnt']], $r['buckets'], [$r['total']]);
            }
            $rows[] = array_merge(['', 'TOTAL', $totals['cnt']], $totals['buckets'], [$totals['total']]);
            $filename = 'AR Aging summary ' . $this->asOf . '.csv';
        }

        $this->download($labels, $rows, $filename);
    }

    /* ------------------------------------------------------------------ */
    /*  Output                                                             */
    /* ------------------------------------------------------------------ */

    /** TEMPORARY: set to false once the report works. Writes ar_aging_debug.log next to this file. */
    const DEBUG = AR_AGING_DEBUG;

    public function debugLog($msg)
    {
        if (!self::DEBUG) {
            return;
        }
        $line = date('Y-m-d H:i:s') . ' ' . $msg . PHP_EOL;
        $ok = @file_put_contents(dirname(__FILE__) . '/ar_aging_debug.log', $line, FILE_APPEND | LOCK_EX);
        if ($ok === false) {
            @file_put_contents(sys_get_temp_dir() . '/ar_aging_debug.log', $line, FILE_APPEND | LOCK_EX);
        }
        @error_log('AR_Aging: ' . trim($line));
    }

    private function enableDebug()
    {
        if (!self::DEBUG) {
            return;
        }
        $self = $this;
        $self->debugLog('process() reached. URI=' . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '') . ' GET=' . json_encode($_REQUEST));
        register_shutdown_function(function () use ($self) {
            $e = error_get_last();
            if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR], true)) {
                $self->debugLog('FATAL: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']);
                echo '<pre style="color:#e5534b">AR Aging fatal error: ' . htmlspecialchars($e['message']) . ' (' . basename($e['file']) . ':' . $e['line'] . ')</pre>';
            }
        });
    }

    public function process()
    {
        $this->enableDebug();

        try {
            $this->processInner();
        } catch (\Throwable $e) {
            $this->debugLog('EXCEPTION: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            echo '<pre style="color:#e5534b">AR Aging error: ' . htmlspecialchars($e->getMessage()) . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')</pre>';
        }
    }

    private function initState()
    {
        $this->detectColumns();
        $this->asOf   = $this->readAsOf();
        $this->bounds = $this->readBuckets();
    }

    private function processInner()
    {
        @set_time_limit(0);
        $this->initState();

        if (isset($_REQUEST['download']) && $_REQUEST['download'] == 1) {
            $this->generate();
            return;
        }

        $this->SetDescription('Accounts receivable aging: unpaid invoices grouped by how long they are overdue.');
        $this->renderFilters();

        if ($this->currentMode() === 'detail') {
            $this->processDetail();
        } else {
            $this->processSummary();
        }
    }

    private function processSummary()
    {
        list($rows, $totals) = $this->getSummaryData();
        $labels = $this->bucketLabels();

        echo "<table width=100%><tr><td><b>Unpaid invoices as of " . $this->h($this->asOf) . ": "
            . $totals['cnt'] . " invoices, " . $this->money($totals['total']) . " Rls</b></td>"
            . "<td align=right><button class='btn' type='button' onclick='window.location.href=\""
            . $this->url(['download' => 1]) . "\"'>Download .csv</button></td></tr></table><br>";

        $group = [];
        foreach ($rows as $r) {
            $line = [
                $this->customerLink($r['customerid'], $r['name']),
                "<a href='" . $this->url(['ar_mode' => 'detail', 'ar_customerid' => $r['customerid']]) . "'>" . $r['cnt'] . '</a>',
            ];
            foreach ($r['buckets'] as $i => $v) {
                $line[] = $this->bucketCell($v, $i);
            }
            $line[] = '<b>' . $this->money($r['total']) . '</b>';
            $group[] = $line;
        }

        // Grand total row
        $line = ['<b>TOTAL</b>', '<b>' . $totals['cnt'] . '</b>'];
        foreach ($totals['buckets'] as $i => $v) {
            $line[] = '<b>' . $this->money($v) . '</b>';
        }
        $line[] = '<b>' . $this->money($totals['total']) . '</b>';
        $group[] = $line;

        $this->reportData[] = [
            'group'     => $group,
            'groupname' => 'AR Aging Summary (balance per customer by age)',
            'label'     => array_merge(['Customer', 'Invoices'], $labels, ['Total (Rls)']),
        ];
    }

    /**
     * Semi-transparent colors: work on both light and dark backgrounds,
     * text keeps the theme's own color. Green = current, then yellow -> red as invoices get older.
     */
    private function bucketColor($index)
    {
        $colors = [
            'rgba(46,160,67,.25)',   // current (not due)
            'rgba(234,179,8,.28)',
            'rgba(249,115,22,.32)',
            'rgba(239,68,68,.35)',
            'rgba(220,38,38,.40)',
            'rgba(220,38,38,.48)',
            'rgba(185,28,28,.55)',
            'rgba(153,27,27,.60)',
            'rgba(127,29,29,.65)',
        ];
        return $colors[max(0, min((int)$index, count($colors) - 1))];
    }

    /** Color-code overdue columns: darker red = older bucket. */
    private function bucketCell($value, $index)
    {
        if ($value <= 0) {
            return '<span style="opacity:.5">-</span>';
        }
        $bg = $this->bucketColor($index);
        return "<span style='background:$bg;color:inherit;padding:2px 8px;border-radius:4px;display:inline-block'>"
            . $this->money($value) . '</span>';
    }

    private function processDetail()
    {
        $page   = max(1, isset($_REQUEST['page']) ? intval($_REQUEST['page']) : 1);
        $count  = isset($_REQUEST['results']) ? max(1, intval($_REQUEST['results'])) : 250;
        $offset = ($page - 1) * $count;

        $res   = $this->getDetailData($count, $offset);
        $total = $res['total'];

        $from = $total ? $offset + 1 : 0;
        $to   = min($offset + $count, $total);
        $prevDisabled = ($page <= 1) ? ' disabled' : '';
        $nextDisabled = ($offset + $count >= $total) ? ' disabled' : '';

        echo '<table width=100%><tr><td>';
        echo "<b>Displaying: $from - $to of $total &nbsp;|&nbsp; Total balance: " . $this->money($res['sum']) . ' Rls</b>';
        echo '</td><td align=right>';
        echo "<button class='btn' type='button' onclick='window.location.href=\"" . $this->url(['download' => 1]) . "\"'>Download .csv</button>";
        echo '</td></tr><tr><td>';
        echo "<button class='btn$prevDisabled' type='button'$prevDisabled onclick='window.location.href=\""
            . $this->url(['page' => $page - 1]) . "\"'>&lt;&nbsp;Previous</button>&nbsp;&nbsp;&nbsp;";
        echo "<button class='btn$nextDisabled' type='button'$nextDisabled onclick='window.location.href=\""
            . $this->url(['page' => $page + 1]) . "\"'>Next&nbsp;&gt;</button>";
        echo '</td><td></td></tr></table><br>';

        $labels = $this->bucketLabels();
        $group  = [];
        foreach ($res['data'] as $r) {
            $idx = array_search($r['bucket'], $labels, true);
            $group[] = [
                $this->invoiceLink($r['id'], $r['customerid']),
                $this->customerLink($r['customerid'], $r['name']),
                $r['billdate'],
                $r['duedate'] . ' <small>(' . $r['jalali'] . ')</small>',
                $r['days'],
                $this->bucketBadge($r['bucket'], $idx === false ? 0 : $idx),
                $this->money($r['balance']),
            ];
        }

        $this->reportData[] = [
            'group'     => $group,
            'groupname' => 'AR Aging Detail (oldest first)',
            'label'     => ['Invoice', 'Customer', 'Bill Date', 'Due Date', 'Days Overdue', 'Bucket', 'Balance (Rls)'],
        ];
    }

    private function bucketBadge($label, $index)
    {
        $bg = $this->bucketColor($index);
        return "<span style='background:$bg;color:inherit;padding:2px 8px;border-radius:4px'>" . $this->h($label) . '</span>';
    }
}