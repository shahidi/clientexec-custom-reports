<?php

require_once 'modules/admin/models/Package.php';
require_once 'modules/clients/models/UserPackage.php';
require_once 'modules/billing/models/Currency.php';
require_once 'modules/clients/models/User.php';
require_once 'modules/billing/models/Invoice.php';

/**
 * Paid Packages (Grouped by Date)
 *
 * Same columns as the original "Paid_Packages" report:
 *   InvoiceID, Amount, FullName, DatePaid, PackageName
 *
 * Improvements:
 *  - One group per day, with Shamsi (Jalali) date, invoice count and daily total in the group title
 *  - Date range filter (From / To) instead of page-based pagination,
 *    so a day is never split across two pages
 *  - Grand total shown at the top
 *  - Per-request caching of users / packages (far fewer queries)
 *  - Dates are validated (no SQL injection through from/to)
 *  - CSV export respects the selected date range
 */
class Paid_Packages_By_Date extends Report
{
    private $lang;
    protected $featureSet = 'support';
    private $label = [];

    private $userCache = [];
    private $pkgCache = [];

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

    public function __construct($user = null, $customer = null)
    {
        $this->lang = lang('Paid Packages By Date');
        parent::__construct($user, $customer);
        $this->label = [
            'InvoiceID',
            'Amount',
            'FullName',
            'DatePaid',
            'PackageName',
        ];
    }

    /* ---------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------- */

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

    private function _validDate($value, $default)
    {
        $d = DateTime::createFromFormat('Y-m-d', (string)$value);
        if ($d && $d->format('Y-m-d') === $value) {
            return $value;
        }
        return $default;
    }

    private function _getRange()
    {
        $from = $this->_validDate(isset($_GET['from']) ? $_GET['from'] : '', date('Y-m-d', strtotime('-30 days')));
        $to   = $this->_validDate(isset($_GET['to']) ? $_GET['to'] : '', date('Y-m-d'));

        if ($from > $to) {
            list($from, $to) = [$to, $from];
        }
        return [$from, $to];
    }

    private function _getFullName($customerId)
    {
        if (!isset($this->userCache[$customerId])) {
            $user = new User($customerId);
            $this->userCache[$customerId] = $user->getFirstName(true) . ' ' . $user->getLastName(true) . ' (' . $user->getId() . ')';
        }
        return $this->userCache[$customerId];
    }

    private function _getPackageName($pkgIDs)
    {
        $out = [];
        foreach ($pkgIDs as $pid) {
            if (!isset($this->pkgCache[$pid])) {
                $userPackage = new UserPackage($pid);
                $this->pkgCache[$pid] = $userPackage->getReference(true);
            }
            $out[] = $this->pkgCache[$pid];
        }
        return implode('<br>', $out);
    }

    /* ---------------------------------------------------------------
     * Data
     * ------------------------------------------------------------- */

    /**
     * Returns rows grouped by date:
     * [ 'YYYY-MM-DD' => ['total' => float, 'count' => int, 'rows' => [...]] ]
     */
    public function _getGroupedData($from, $to)
    {
        // $from / $to are validated Y-m-d strings, safe to embed
        $sql = "
        SELECT
            i.id AS InvoiceID,
            i.customerid AS CustomerID,
            i.amount AS Amount,
            it.transactiondate AS TransDate
        FROM invoice i
        INNER JOIN (
            SELECT invoiceid, MAX(transactiondate) AS LatestTransDate
            FROM invoicetransaction
            WHERE action = 'charge'
            GROUP BY invoiceid
        ) latest_it ON i.id = latest_it.invoiceid
        INNER JOIN invoicetransaction it
            ON it.invoiceid = latest_it.invoiceid
            AND it.transactiondate = latest_it.LatestTransDate
            AND it.action = 'charge'
        WHERE i.status = 1
            AND DATE(it.transactiondate) BETWEEN \"{$from}\" AND \"{$to}\"
        ORDER BY it.transactiondate DESC, i.id DESC";

        $result = $this->db->query($sql);
        $groups = [];

        while ($row = $result->fetch()) {
            $day = substr($row['TransDate'], 0, 10);

            $invoice = new Invoice($row['InvoiceID']);
            $pkgName = $this->_getPackageName($invoice->getAllPackageIDs());

            if (!isset($groups[$day])) {
                $groups[$day] = ['total' => 0, 'count' => 0, 'rows' => []];
            }

            $groups[$day]['total'] += (float)$row['Amount'];
            $groups[$day]['count']++;
            $groups[$day]['rows'][] = [
                "<a href='index.php?fuse=billing&controller=invoice&view=invoice&profile=1&frmClientID={$row['CustomerID']}&invoiceid={$row['InvoiceID']}' target='_blank'>#{$row['InvoiceID']}</a>",
                $row['Amount'],
                $this->_getFullName($row['CustomerID']),
                $row['TransDate'],
                "<small>{$pkgName}</small>",
            ];
        }

        return $groups;
    }

    /* ---------------------------------------------------------------
     * CSV
     * ------------------------------------------------------------- */

    public function generate()
    {
        list($from, $to) = $this->_getRange();
        $filename = "Paid_Packages_{$from}_to_{$to}.csv";

        ob_clean();
        header('Content-Type: text/csv; charset=UTF-8');
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        header('Pragma: no-cache');
        header('Expires: 0');

        echo "\u{FEFF}"; // UTF-8 BOM for Excel
        echo implode(',', $this->label) . "\n";

        foreach ($this->_getGroupedData($from, $to) as $group) {
            foreach ($group['rows'] as $row) {
                $cleaned = array_map(function ($value) {
                    $value = str_replace('<br>', ' | ', $value);
                    return '"' . str_replace('"', '""', strip_tags($value)) . '"';
                }, $row);
                echo implode(',', $cleaned) . "\n";
            }
        }
        exit;
    }

    /* ---------------------------------------------------------------
     * Page
     * ------------------------------------------------------------- */

    public function process()
    {
        $this->SetDescription($this->user->lang('Paid Packages By Date'));

        if (isset($_GET['generate'])) {
            $this->generate();
            return;
        }

        list($from, $to) = $this->_getRange();
        $groups = $this->_getGroupedData($from, $to);

        $grandTotal = 0;
        $grandCount = 0;
        foreach ($groups as $g) {
            $grandTotal += $g['total'];
            $grandCount += $g['count'];
        }

        // Keep the current report URL params (fuse, controller, view, report...)
        $hidden = '';
        foreach ($_GET as $k => $v) {
            if (in_array($k, ['from', 'to', 'generate', 'page', 'results'], true) || is_array($v)) {
                continue;
            }
            $hidden .= '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars($v) . '">';
        }

        $csvParams = $_GET;
        unset($csvParams['page'], $csvParams['results']);
        $csvParams['from'] = $from;
        $csvParams['to'] = $to;
        $csvParams['generate'] = 1;
        $csvUrl = 'index.php?' . http_build_query($csvParams);

        // Filter bar + summary
        echo '<form method="get" action="index.php" style="margin-bottom:10px">';
        echo $hidden;
        echo '<b>From</b> <input type="date" name="from" value="' . $from . '"> ';
        echo '<b>To</b> <input type="date" name="to" value="' . $to . '"> ';
        echo '<button class="btn" type="submit">' . $this->user->lang('Filter') . '</button> ';
        echo '<button class="btn" type="button" onclick="window.location.href=\'' . $csvUrl . '\'">' . $this->user->lang('Download .csv') . '</button>';
        echo '</form>';

        echo '<p><b>' . $grandCount . '</b> paid invoices in <b>' . count($groups) . '</b> days &mdash; ';
        echo 'Total: <b>' . number_format($grandTotal, 2) . '</b></p>';

        // One group per day (newest first)
        foreach ($groups as $day => $g) {
            // RLI/PDI isolate the RTL Persian text; LRM keeps the parentheses in LTR order
            $title = date('D, d M Y', strtotime($day))
                . " (\u{2067}" . $this->_shamsiDate($day) . "\u{2069})\u{200E}"
                . ' — ' . $g['count'] . ' invoices — Total: ' . number_format($g['total'], 2);

            $this->reportData[] = [
                'group'     => $g['rows'],
                'groupname' => $title,
                'label'     => $this->label,
            ];
        }

        if (empty($groups)) {
            echo '<p>No paid invoices found for this date range.</p>';
        }
    }
}
?>