<?php

/**
 * Navigation.php
 * Copyright (c) 2019 james@firefly-iii.org
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

namespace FireflyIII\Support;

use Carbon\Carbon;
use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Helpers\Fiscal\FiscalHelperInterface;
use Illuminate\Support\Facades\Log;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Class Navigation.
 */
class Navigation
{
    /**
     * @param  Carbon  $theDate
     * @param  string  $repeatFreq
     * @param  int  $skip
     *
     * @return Carbon
     */
    public function addPeriod(Carbon $theDate, string $repeatFreq, int $skip): Carbon
    {
        $date = clone $theDate;
        $add  = ($skip + 1);

        $functionMap = [
            '1D'        => 'addDays',
            'daily'     => 'addDays',
            '1W'        => 'addWeeks',
            'weekly'    => 'addWeeks',
            'week'      => 'addWeeks',
            '1M'        => 'addMonths',
            'month'     => 'addMonths',
            'monthly'   => 'addMonths',
            '3M'        => 'addMonths',
            'quarter'   => 'addMonths',
            'quarterly' => 'addMonths',
            '6M'        => 'addMonths',
            'half-year' => 'addMonths',
            'year'      => 'addYears',
            'yearly'    => 'addYears',
            '1Y'        => 'addYears',
            'custom'    => 'addMonths', // custom? just add one month.
            // last X periods? Jump the relevant month / quarter / year
            'last7'     => 'addDays',
            'last30'    => 'addMonths',
            'last90'    => 'addMonths',
            'last365'   => 'addYears',
            'MTD'       => 'addMonths',
            'QTD'       => 'addMonths',
            'YTD'       => 'addYears',
        ];
        $modifierMap = [
            'quarter'   => 3,
            '3M'        => 3,
            'quarterly' => 3,
            '6M'        => 6,
            'half-year' => 6,
            'last7'     => 7,
            'last90'    => 3,
            'QTD'       => 3,
        ];

        if (!array_key_exists($repeatFreq, $functionMap)) {
            Log::error(sprintf('Cannot do addPeriod for $repeat_freq "%s"', $repeatFreq));

            return $theDate;
        }
        if (array_key_exists($repeatFreq, $modifierMap)) {
            $add *= $modifierMap[$repeatFreq];
        }
        $function = $functionMap[$repeatFreq];
        $date->$function($add);

        // if period is 1M and diff in month is 2 and new DOM > 1, sub a number of days:
        // AND skip is 1
        // result is:
        // '2019-01-29', '2019-02-28'
        // '2019-01-30', '2019-02-28'
        // '2019-01-31', '2019-02-28'

        $months     = ['1M', 'month', 'monthly'];
        $difference = $date->month - $theDate->month;
        if (1 === $add && 2 === $difference && $date->day > 0 && in_array($repeatFreq, $months, true)) {
            $date->subDays($date->day);
        }

        return $date;
    }

    /**
     * @param  Carbon  $start
     * @param  Carbon  $end
     * @param  string  $range
     *
     * @return array
     *
     */
    public function blockPeriods(Carbon $start, Carbon $end, string $range): array
    {
        if ($end < $start) {
            [$start, $end] = [$end, $start];
        }
        $periods = [];
        // first, 13 periods of [range]
        $loopCount = 0;
        $loopDate  = clone $end;
        $workStart = clone $loopDate;
        $workEnd   = clone $loopDate;
        while ($loopCount < 13) {
            // make range:
            $workStart = \Navigation::startOfPeriod($workStart, $range);
            $workEnd   = \Navigation::endOfPeriod($workStart, $range);

            // make sure we don't go overboard
            if ($workEnd->gt($start)) {
                $periods[] = [
                    'start'  => clone $workStart,
                    'end'    => clone $workEnd,
                    'period' => $range,
                ];
            }
            // skip to the next period:
            $workStart->subDay()->startOfDay();
            $loopCount++;
        }
        // if $workEnd is still before $start, continue on a yearly basis:
        $loopCount = 0;
        if ($workEnd->gt($start)) {
            while ($workEnd->gt($start) && $loopCount < 20) {
                // make range:
                $workStart = app('navigation')->startOfPeriod($workStart, '1Y');
                $workEnd   = app('navigation')->endOfPeriod($workStart, '1Y');

                // make sure we don't go overboard
                if ($workEnd->gt($start)) {
                    $periods[] = [
                        'start'  => clone $workStart,
                        'end'    => clone $workEnd,
                        'period' => '1Y',
                    ];
                }
                // skip to the next period:
                $workStart->subDay()->startOfDay();
                $loopCount++;
            }
        }

        return $periods;
    }

    /**
     * @param  Carbon  $theDate
     * @param  string  $repeatFreq
     *
     * @return Carbon
     */
    public function startOfPeriod(Carbon $theDate, string $repeatFreq): Carbon
    {
        $date = clone $theDate;

        $functionMap = [
            '1D'        => 'startOfDay',
            'daily'     => 'startOfDay',
            '1W'        => 'startOfWeek',
            'week'      => 'startOfWeek',
            'weekly'    => 'startOfWeek',
            'month'     => 'startOfMonth',
            '1M'        => 'startOfMonth',
            'monthly'   => 'startOfMonth',
            '3M'        => 'firstOfQuarter',
            'quarter'   => 'firstOfQuarter',
            'quarterly' => 'firstOfQuarter',
            'year'      => 'startOfYear',
            'yearly'    => 'startOfYear',
            '1Y'        => 'startOfYear',
        ];
        if (array_key_exists($repeatFreq, $functionMap)) {
            $function = $functionMap[$repeatFreq];
            $date->$function();

            return $date;
        }
        if ('half-year' === $repeatFreq || '6M' === $repeatFreq) {
            $month = $date->month;
            $date->startOfYear();
            if ($month >= 7) {
                $date->addMonths(6);
            }

            return $date;
        }

        $result = match ($repeatFreq) {
            'last7' => $date->subDays(7)->startOfDay(),
            'last30' => $date->subDays(30)->startOfDay(),
            'last90' => $date->subDays(90)->startOfDay(),
            'last365' => $date->subDays(365)->startOfDay(),
            'MTD' => $date->startOfMonth()->startOfDay(),
            'QTD' => $date->firstOfQuarter()->startOfDay(),
            'YTD' => $date->startOfYear()->startOfDay(),
            default => null,
        };
        if (null !== $result) {
            return $result;
        }


        if ('custom' === $repeatFreq) {
            return $date; // the date is already at the start.
        }
        Log::error(sprintf('Cannot do startOfPeriod for $repeat_freq "%s"', $repeatFreq));

        return $theDate;
    }

    /**
     * @param  Carbon  $end
     * @param  string  $repeatFreq
     *
     * @return Carbon
     */
    public function endOfPeriod(Carbon $end, string $repeatFreq): Carbon
    {
        $currentEnd = clone $end;

        $functionMap = [
            '1D'        => 'endOfDay',
            'daily'     => 'endOfDay',
            '1W'        => 'addWeek',
            'week'      => 'addWeek',
            'weekly'    => 'addWeek',
            '1M'        => 'addMonth',
            'month'     => 'addMonth',
            'monthly'   => 'addMonth',
            '3M'        => 'addMonths',
            'quarter'   => 'addMonths',
            'quarterly' => 'addMonths',
            '6M'        => 'addMonths',
            'half-year' => 'addMonths',
            'half_year' => 'addMonths',
            'year'      => 'addYear',
            'yearly'    => 'addYear',
            '1Y'        => 'addYear',
        ];
        $modifierMap = [
            'quarter'   => 3,
            '3M'        => 3,
            'quarterly' => 3,
            'half-year' => 6,
            'half_year' => 6,
            '6M'        => 6,
        ];

        $subDay = ['week', 'weekly', '1W', 'month', 'monthly', '1M', '3M', 'quarter', 'quarterly', '6M', 'half-year', 'half_year', '1Y', 'year', 'yearly'];

        // if the range is custom, the end of the period
        // is another X days (x is the difference between start)
        // and end added to $theCurrentEnd
        if ('custom' === $repeatFreq) {
            /** @var Carbon $tStart */
            $tStart = session('start', today(config('app.timezone'))->startOfMonth());
            /** @var Carbon $tEnd */
            $tEnd       = session('end', today(config('app.timezone'))->endOfMonth());
            $diffInDays = $tStart->diffInDays($tEnd);
            $currentEnd->addDays($diffInDays);

            return $currentEnd;
        }

        $result = match ($repeatFreq) {
            'last7' => $currentEnd->addDays(7)->startOfDay(),
            'last30' => $currentEnd->addDays(30)->startOfDay(),
            'last90' => $currentEnd->addDays(90)->startOfDay(),
            'last365' => $currentEnd->addDays(365)->startOfDay(),
            'MTD' => $currentEnd->startOfMonth()->startOfDay(),
            'QTD' => $currentEnd->firstOfQuarter()->startOfDay(),
            'YTD' => $currentEnd->startOfYear()->startOfDay(),
            default => null,
        };
        if (null !== $result) {
            return $result;
        }
        unset($result);


        if (!array_key_exists($repeatFreq, $functionMap)) {
            Log::error(sprintf('Cannot do endOfPeriod for $repeat_freq "%s"', $repeatFreq));

            return $end;
        }
        $function = $functionMap[$repeatFreq];

        if (array_key_exists($repeatFreq, $modifierMap)) {
            $currentEnd->$function($modifierMap[$repeatFreq]);
            if (in_array($repeatFreq, $subDay, true)) {
                $currentEnd->subDay();
            }
            $currentEnd->endOfDay();

            return $currentEnd;
        }
        $currentEnd->$function();
        $currentEnd->endOfDay();
        if (in_array($repeatFreq, $subDay, true)) {
            $currentEnd->subDay();
        }

        return $currentEnd;
    }

    /**
     * @param  Carbon  $theCurrentEnd
     * @param  string  $repeatFreq
     * @param  Carbon|null  $maxDate
     *
     * @return Carbon
     */
    public function endOfX(Carbon $theCurrentEnd, string $repeatFreq, ?Carbon $maxDate): Carbon
    {
        $functionMap = [
            '1D'        => 'endOfDay',
            'daily'     => 'endOfDay',
            '1W'        => 'endOfWeek',
            'week'      => 'endOfWeek',
            'weekly'    => 'endOfWeek',
            'month'     => 'endOfMonth',
            '1M'        => 'endOfMonth',
            'monthly'   => 'endOfMonth',
            '3M'        => 'lastOfQuarter',
            'quarter'   => 'lastOfQuarter',
            'quarterly' => 'lastOfQuarter',
            '1Y'        => 'endOfYear',
            'year'      => 'endOfYear',
            'yearly'    => 'endOfYear',
        ];

        $currentEnd = clone $theCurrentEnd;

        if (array_key_exists($repeatFreq, $functionMap)) {
            $function = $functionMap[$repeatFreq];
            $currentEnd->$function();
        }

        if (null !== $maxDate && $currentEnd > $maxDate) {
            return clone $maxDate;
        }

        return $currentEnd;
    }

    /**
     * Returns the user's view range and if necessary, corrects the dynamic view
     * range to a normal range.
     * @param  bool  $correct
     * @return string
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function getViewRange(bool $correct): string
    {
        $range = (string)app('preferences')->get('viewRange', '1M')?->data ?? '1M';
        if (!$correct) {
            return $range;
        }
        switch ($range) {
            default:
                return $range;
            case 'last7':
                return '1W';
            case 'last30':
            case 'MTD':
                return '1M';
            case 'last90':
            case 'QTD':
                return '3M';
            case 'last365':
            case 'YTD':
                return '1Y';
        }
    }

    /**
     * @param  Carbon  $start
     * @param  Carbon  $end
     *
     * @return array
     * @throws FireflyException
     */
    public function listOfPeriods(Carbon $start, Carbon $end): array
    {
        $locale = app('steam')->getLocale();
        // define period to increment
        $increment     = 'addDay';
        $format        = $this->preferredCarbonFormat($start, $end);
        $displayFormat = (string)trans('config.month_and_day_js', [], $locale);
        // increment by month (for year)
        if ($start->diffInMonths($end) > 1) {
            $increment     = 'addMonth';
            $displayFormat = (string)trans('config.month_js');
        }

        // increment by year (for multi year)
        if ($start->diffInMonths($end) > 12) {
            $increment     = 'addYear';
            $displayFormat = (string)trans('config.year_js');
        }
        $begin   = clone $start;
        $entries = [];
        while ($begin < $end) {
            $formatted           = $begin->format($format);
            $displayed           = $begin->isoFormat($displayFormat);
            $entries[$formatted] = $displayed;
            $begin->$increment();
        }

        return $entries;
    }

    /**
     * If the date difference between start and end is less than a month, method returns "Y-m-d". If the difference is less than a year,
     * method returns "Y-m". If the date difference is larger, method returns "Y".
     *
     * @param  Carbon  $start
     * @param  Carbon  $end
     *
     * @return string
     */
    public function preferredCarbonFormat(Carbon $start, Carbon $end): string
    {
        $format = 'Y-m-d';
        if ($start->diffInMonths($end) > 1) {
            $format = 'Y-m';
        }

        if ($start->diffInMonths($end) > 12) {
            $format = 'Y';
        }

        return $format;
    }

    /**
     * @param  Carbon  $theDate
     * @param  string  $repeatFrequency
     *
     * @return string
     */
    public function periodShow(Carbon $theDate, string $repeatFrequency): string
    {
        $date      = clone $theDate;
        $formatMap = [
            '1D'      => (string)trans('config.specific_day_js'),
            'daily'   => (string)trans('config.specific_day_js'),
            'custom'  => (string)trans('config.specific_day_js'),
            '1W'      => (string)trans('config.week_in_year_js'),
            'week'    => (string)trans('config.week_in_year_js'),
            'weekly'  => (string)trans('config.week_in_year_js'),
            '1M'      => (string)trans('config.month_js'),
            'month'   => (string)trans('config.month_js'),
            'monthly' => (string)trans('config.month_js'),
            '1Y'      => (string)trans('config.year_js'),
            'year'    => (string)trans('config.year_js'),
            'yearly'  => (string)trans('config.year_js'),
            '6M'      => (string)trans('config.half_year_js'),
        ];

        if (array_key_exists($repeatFrequency, $formatMap)) {
            return $date->isoFormat((string)$formatMap[$repeatFrequency]);
        }
        if ('3M' === $repeatFrequency || 'quarter' === $repeatFrequency) {
            $quarter = ceil($theDate->month / 3);

            return sprintf('Q%d %d', $quarter, $theDate->year);
        }

        // special formatter for quarter of year
        Log::error(sprintf('No date formats for frequency "%s"!', $repeatFrequency));

        return $date->format('Y-m-d');
    }

    /**
     * If the date difference between start and end is less than a month, method returns trans(config.month_and_day). If the difference is less than a year,
     * method returns "config.month". If the date difference is larger, method returns "config.year".
     *
     * @param  Carbon  $start
     * @param  Carbon  $end
     *
     * @return string
     */
    public function preferredCarbonLocalizedFormat(Carbon $start, Carbon $end): string
    {
        $locale = app('steam')->getLocale();
        $format = (string)trans('config.month_and_day_js', [], $locale);
        if ($start->diffInMonths($end) > 1) {
            $format = (string)trans('config.month_js', [], $locale);
        }

        if ($start->diffInMonths($end) > 12) {
            $format = (string)trans('config.year_js', [], $locale);
        }

        return $format;
    }

    /**
     * If the date difference between start and end is less than a month, method returns "endOfDay". If the difference is less than a year,
     * method returns "endOfMonth". If the date difference is larger, method returns "endOfYear".
     *
     * @param  Carbon  $start
     * @param  Carbon  $end
     *
     * @return string
     */
    public function preferredEndOfPeriod(Carbon $start, Carbon $end): string
    {
        $format = 'endOfDay';
        if ($start->diffInMonths($end) > 1) {
            $format = 'endOfMonth';
        }

        if ($start->diffInMonths($end) > 12) {
            $format = 'endOfYear';
        }

        return $format;
    }

    /**
     * If the date difference between start and end is less than a month, method returns "1D". If the difference is less than a year,
     * method returns "1M". If the date difference is larger, method returns "1Y".
     *
     * @param  Carbon  $start
     * @param  Carbon  $end
     *
     * @return string
     */
    public function preferredRangeFormat(Carbon $start, Carbon $end): string
    {
        $format = '1D';
        if ($start->diffInMonths($end) > 1) {
            $format = '1M';
        }

        if ($start->diffInMonths($end) > 12) {
            $format = '1Y';
        }

        return $format;
    }

    /**
     * If the date difference between start and end is less than a month, method returns "%Y-%m-%d". If the difference is less than a year,
     * method returns "%Y-%m". If the date difference is larger, method returns "%Y".
     *
     * @param  Carbon  $start
     * @param  Carbon  $end
     *
     * @return string
     */
    public function preferredSqlFormat(Carbon $start, Carbon $end): string
    {
        $format = '%Y-%m-%d';
        if ($start->diffInMonths($end) > 1) {
            $format = '%Y-%m';
        }

        if ($start->diffInMonths($end) > 12) {
            $format = '%Y';
        }

        return $format;
    }

    /**
     * @param  Carbon  $theDate
     * @param  string  $repeatFreq
     * @param  int|null  $subtract
     *
     * @return Carbon
     *
     * @throws FireflyException
     */
    public function subtractPeriod(Carbon $theDate, string $repeatFreq, int $subtract = null): Carbon
    {
        $subtract = $subtract ?? 1;
        $date     = clone $theDate;
        // 1D 1W 1M 3M 6M 1Y
        $functionMap = [
            '1D'      => 'subDays',
            'daily'   => 'subDays',
            'week'    => 'subWeeks',
            '1W'      => 'subWeeks',
            'weekly'  => 'subWeeks',
            'month'   => 'subMonths',
            '1M'      => 'subMonths',
            'monthly' => 'subMonths',
            'year'    => 'subYears',
            '1Y'      => 'subYears',
            'yearly'  => 'subYears',
        ];
        $modifierMap = [
            'quarter'   => 3,
            '3M'        => 3,
            'quarterly' => 3,
            'half-year' => 6,
            '6M'        => 6,
        ];
        if (array_key_exists($repeatFreq, $functionMap)) {
            $function = $functionMap[$repeatFreq];
            $date->$function($subtract);

            return $date;
        }
        if (array_key_exists($repeatFreq, $modifierMap)) {
            $subtract *= $modifierMap[$repeatFreq];
            $date->subMonths($subtract);

            return $date;
        }
        // a custom range requires the session start
        // and session end to calculate the difference in days.
        // this is then subtracted from $theDate (* $subtract).
        if ('custom' === $repeatFreq) {
            /** @var Carbon $tStart */
            $tStart = session('start', today(config('app.timezone'))->startOfMonth());
            /** @var Carbon $tEnd */
            $tEnd       = session('end', today(config('app.timezone'))->endOfMonth());
            $diffInDays = $tStart->diffInDays($tEnd);
            $date->subDays($diffInDays * $subtract);

            return $date;
        }
        switch ($repeatFreq) {
            default:
                break;
            case 'last7':
                $date->subDays(7);
                return $date;
            case 'last30':
                $date->subDays(30);
                return $date;
            case 'last90':
                $date->subDays(90);
                return $date;
            case 'last365':
                $date->subDays(365);
                return $date;
            case 'YTD':
                $date->subYear();
                return $date;
            case 'QTD':
                $date->subQuarter();
                return $date;
            case 'MTD':
                $date->subMonth();
                return $date;
        }


        throw new FireflyException(sprintf('Cannot do subtractPeriod for $repeat_freq "%s"', $repeatFreq));
    }

    /**
     * @param  string  $range
     * @param  Carbon  $start
     *
     * @return Carbon
     *
     * @throws FireflyException
     */
    public function updateEndDate(string $range, Carbon $start): Carbon
    {
        Log::debug(sprintf('updateEndDate("%s", "%s")', $range, $start->format('Y-m-d')));
        $functionMap = [
            '1D'     => 'endOfDay',
            '1W'     => 'endOfWeek',
            '1M'     => 'endOfMonth',
            '3M'     => 'lastOfQuarter',
            'custom' => 'startOfMonth', // this only happens in test situations.
        ];
        $end         = clone $start;

        if (array_key_exists($range, $functionMap)) {
            $function = $functionMap[$range];
            $end->$function();

            return $end;
        }
        if ('6M' === $range) {
            if ($start->month >= 7) {
                $end->endOfYear();

                return $end;
            }
            $end->startOfYear()->addMonths(6);

            return $end;
        }

        // make sure 1Y takes the fiscal year into account.
        if ('1Y' === $range) {
            /** @var FiscalHelperInterface $fiscalHelper */
            $fiscalHelper = app(FiscalHelperInterface::class);

            return $fiscalHelper->endOfFiscalYear($end);
        }
        $list = [
            'last7',
            'last30',
            'last90',
            'last365',
            'YTD',
            'QTD',
            'MTD',
        ];
        if (in_array($range, $list, true)) {
            $end = today(config('app.timezone'));
            $end->endOfDay();
            Log::debug(sprintf('updateEndDate returns "%s"', $end->format('Y-m-d')));
            return $end;
        }

        throw new FireflyException(sprintf('updateEndDate cannot handle range "%s"', $range));
    }

    /**
     * @param  string  $range
     * @param  Carbon  $start
     *
     * @return Carbon
     *
     * @throws FireflyException
     */
    public function updateStartDate(string $range, Carbon $start): Carbon
    {
        Log::debug(sprintf('updateStartDate("%s", "%s")', $range, $start->format('Y-m-d')));
        $functionMap = [
            '1D'     => 'startOfDay',
            '1W'     => 'startOfWeek',
            '1M'     => 'startOfMonth',
            '3M'     => 'firstOfQuarter',
            'custom' => 'startOfMonth', // this only happens in test situations.
        ];
        if (array_key_exists($range, $functionMap)) {
            $function = $functionMap[$range];
            $start->$function();

            return $start;
        }
        if ('6M' === $range) {
            if ($start->month >= 7) {
                $start->startOfYear()->addMonths(6);

                return $start;
            }
            $start->startOfYear();

            return $start;
        }

        // make sure 1Y takes the fiscal year into account.
        if ('1Y' === $range) {
            /** @var FiscalHelperInterface $fiscalHelper */
            $fiscalHelper = app(FiscalHelperInterface::class);

            return $fiscalHelper->startOfFiscalYear($start);
        }
        switch ($range) {
            default:
                break;
            case 'last7':
                $start->subDays(7);
                return $start;
            case 'last30':
                $start->subDays(30);
                return $start;
            case 'last90':
                $start->subDays(90);
                return $start;
            case 'last365':
                $start->subDays(365);
                return $start;
            case 'YTD':
                $start->startOfYear();
                return $start;
            case 'QTD':
                $start->startOfQuarter();
                return $start;
            case 'MTD':
                $start->startOfMonth();
                return $start;
        }
        throw new FireflyException(sprintf('updateStartDate cannot handle range "%s"', $range));
    }
}
