<?php

require_once 'modules/clients/models/User.php';
require_once 'modules/billing/models/Invoice.php';

class Paid_Invoice_By_Date extends Report
{
    private $lang;
    protected $featureSet = 'support';

    // Defined columns (order matters, keys map to the row arrays below)
    private $label = [
        'InvoiceID',
        'FullName',
        'Note',
        'DatePaid',
        'BalanceDue',
        'Amount',
        'TransDate',
        'TransID',
        'Actions',
        'TransAmount'
    ];

    public function __construct($user = null, $customer = null)
    {
        $this->lang = lang('Paid Invoice By Date');
        parent::__construct($user, $customer);
    }

    // Set to false to show Latin digits and transliterated month names (e.g. "21 Aban 1405")
    private $persianScript = true;

    private $jalaliMonthsFa = [
        'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
        'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'
    ];

    private $jalaliMonthsEn = [
        'Farvardin', 'Ordibehesht', 'Khordad', 'Tir', 'Mordad', 'Shahrivar',
        'Mehr', 'Aban', 'Azar', 'Dey', 'Bahman', 'Esfand'
    ];

    /**
     * Convert a Gregorian date to Jalali (Shamsi). Returns [year, month, day].
     */
    private function _gregorianToJalali($gy, $gm, $gd)
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

    /**
     * Format a YYYY-MM-DD date as a Shamsi string, e.g. "۲۱ آبان ۱۴۰۵".
     */
    private function _shamsiDate($date)
    {
        list($gy, $gm, $gd) = array_map('intval', explode('-', substr($date, 0, 10)));
        list($jy, $jm, $jd) = $this->_gregorianToJalali($gy, $gm, $gd);

        if ($this->persianScript) {
            $str = $jd . ' ' . $this->jalaliMonthsFa[$jm - 1] . ' ' . $jy;
            return strtr($str, [
                '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
                '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹'
            ]);
        }

        return $jd . ' ' . $this->jalaliMonthsEn[$jm - 1] . ' ' . $jy;
    }

    /**
     * Read and validate date range from GET (YYYY-MM-DD). Defaults to last 30 days.
     */
    private function _getDateRange()
    {
        $valid = function ($d) {
            $dt = DateTime::createFromFormat('Y-m-d', (string)$d);
            return ($dt && $dt->format('Y-m-d') === $d) ? $d : null;
        };

        $from = isset($_GET['from']) ? $valid($_GET['from']) : null;
        $to   = isset($_GET['to'])   ? $valid($_GET['to'])   : null;

        if (!$to)   { $to   = date('Y-m-d'); }
        if (!$from) { $from = date('Y-m-d', strtotime('-30 days')); }
        if ($from > $to) { list($from, $to) = [$to, $from]; }

        return [$from, $to];
    }

    /**
     * Fetch paid invoices in range, grouped by paid date.
     * Returns [ 'YYYY-MM-DD' => [ rows... ], ... ] (newest date first).
     */
    private function _getGroupedData($from, $to)
    {
        $from = $this->db->escape_string($from);
        $to   = $this->db->escape_string($to);

        $sql = "SELECT
                    invoice.id AS InvoiceID,
                    invoice.customerid AS CustomerID,
                    invoice.checknum AS Note,
                    invoice.datepaid AS DatePaid,
                    invoice.balance_due AS BalanceDue,
                    invoice.amount AS Amount,
                    invoicetransaction.transactiondate AS TransDate,
                    invoicetransaction.transactionid AS TransID,
                    invoicetransaction.action AS Actions,
                    invoicetransaction.amount AS TransAmount
                FROM invoice
                INNER JOIN invoicetransaction ON invoice.id = invoicetransaction.invoiceid
                WHERE invoice.status = 1
                  AND DATE(invoice.datepaid) BETWEEN '$from' AND '$to'
                GROUP BY invoice.id
                ORDER BY invoice.datepaid DESC, invoice.id DESC";

        $db = $this->db->query($sql);
        $groups = [];

        while ($row = $db->fetch()) {
            $user = new User($row['CustomerID']);
            $fullname = $user->getFullName(true) . ' (' . $user->getId() . ')';
            $dateKey = substr($row['DatePaid'], 0, 10);

            $groups[$dateKey][] = [
                'raw' => $row,
                'fullname' => $fullname,
            ];
        }

        return $groups;
    }

    /**
     * Build one display/CSV row from a record.
     */
    private function _buildRow($rec, $link = true)
    {
        $r = $rec['raw'];
        $id = $r['InvoiceID'];

        if ($link) {
            $invoiceCol = "<a href=\"index.php?fuse=billing&controller=invoice&view=invoice&profile=1&frmClientID="
                . (int)$r['CustomerID'] . "&invoiceid=" . (int)$id . "\" target=\"_blank\">#" . (int)$id . "</a>";
        } else {
            $invoiceCol = '#' . (int)$id;
        }

        return [
            $invoiceCol,
            $rec['fullname'],
            $r['Note'],
            $r['DatePaid'],
            $r['BalanceDue'],
            number_format($r['Amount'], 2),
            $r['TransDate'],
            $r['TransID'],
            $r['Actions'],
            number_format($r['TransAmount'], 2),
        ];
    }

    /**
     * Daily total row aligned with the defined columns.
     */
    private function _buildTotalRow($records)
    {
        $amount = 0;
        $transAmount = 0;
        foreach ($records as $rec) {
            $amount      += (float)$rec['raw']['Amount'];
            $transAmount += (float)$rec['raw']['TransAmount'];
        }

        return [
            '<b>Day total (' . count($records) . ' invoices)</b>',
            '', '', '', '',
            '<b>' . number_format($amount, 2) . '</b>',
            '', '', '',
            '<b>' . number_format($transAmount, 2) . '</b>',
        ];
    }

    public function generate()
    {
        list($from, $to) = $this->_getDateRange();

        ob_clean();
        $filename = "Paid_Invoice_By_Date_{$from}_to_{$to}.csv";

        header('Content-Encoding: UTF-8');
        header('Content-Type: text/csv; charset=UTF-8');
        header("Content-Disposition: attachment; filename=\"$filename\"");
        header('Pragma: no-cache');
        header('Expires: 0');

        echo "\u{FEFF}"; // BOM for Excel
        echo implode(',', array_merge(['GroupDate'], $this->label)) . "\n";

        $esc = function ($v) {
            return '"' . str_replace('"', '""', strip_tags((string)$v)) . '"';
        };

        foreach ($this->_getGroupedData($from, $to) as $date => $records) {
            foreach ($records as $rec) {
                $row = $this->_buildRow($rec, false);
                echo $esc($date) . ',' . implode(',', array_map($esc, $row)) . "\n";
            }
        }

        exit;
    }

    public function process()
    {
        $this->SetDescription($this->user->lang('Paid Invoice By Date'));

        if (isset($_GET['generate'])) {
            $this->generate();
            return;
        }

        list($from, $to) = $this->_getDateRange();
        $groups = $this->_getGroupedData($from, $to);

        // CSV URL keeps current filters
        $q = $_GET;
        $q['from'] = $from;
        $q['to'] = $to;
        $q['generate'] = 1;
        $csvUrl = 'index.php?' . http_build_query($q);

        // Filter form (preserves fuse/report params)
        $hidden = '';
        foreach ($_GET as $k => $v) {
            if (in_array($k, ['from', 'to', 'generate'], true) || is_array($v)) { continue; }
            $hidden .= '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars($v) . '">';
        }

        $totalInvoices = 0;
        foreach ($groups as $records) { $totalInvoices += count($records); }

        echo '<form method="get" action="index.php" style="margin-bottom:10px;">';
        echo $hidden;
        echo 'From: <input type="date" name="from" value="' . htmlspecialchars($from) . '"> ';
        echo 'To: <input type="date" name="to" value="' . htmlspecialchars($to) . '"> ';
        echo '<button class="btn" type="submit">Filter</button> ';
        echo "<button class='btn' type='button' onclick='window.location.href=\"" . htmlspecialchars($csvUrl, ENT_QUOTES) . "\"'>Download .csv</button>";
        echo '</form>';
        echo '<b>' . $totalInvoices . ' paid invoices across ' . count($groups) . ' days (' . htmlspecialchars($from) . ' to ' . htmlspecialchars($to) . ')</b>';

        // One report group per date
        foreach ($groups as $date => $records) {
            $rows = [];
            foreach ($records as $rec) {
                $rows[] = $this->_buildRow($rec, true);
            }
            $rows[] = $this->_buildTotalRow($records);

            $this->reportData[] = [
                'group'     => $rows,
                // RLI/PDI isolate the RTL Persian text; LRM keeps the parentheses in LTR order
                'groupname' => date('l, d M Y', strtotime($date))
                    . " (\u{2067}" . $this->_shamsiDate($date) . "\u{2069})\u{200E}",
                'label'     => $this->label,
            ];
        }
    }
}
?>