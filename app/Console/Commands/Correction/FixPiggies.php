<?php
/**
 * FixPiggies.php
 * Copyright (c) 2020 james@firefly-iii.org
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

namespace FireflyIII\Console\Commands\Correction;

use FireflyIII\Models\PiggyBankEvent;
use FireflyIII\Models\TransactionJournal;
use Illuminate\Console\Command;

/**
 * Report (and fix) piggy banks.
 *
 * Class FixPiggies
 */
class FixPiggies extends Command
{
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fixes common issues with piggy banks.';
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'firefly-iii:fix-piggies';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $count = 0;
        $start = microtime(true);
        $set   = PiggyBankEvent::with(['PiggyBank', 'TransactionJournal'])->get();

        /** @var PiggyBankEvent $event */
        foreach ($set as $event) {
            if (null === $event->transaction_journal_id) {
                continue;
            }
            /** @var TransactionJournal|null $journal */
            $journal = $event->transactionJournal;

            if (null === $journal) {
                $event->transaction_journal_id = null;
                $event->save();
                $count++;
                continue;
            }
        }
        if (0 === $count) {
            $this->line('All piggy bank events are correct.');
        }
        if (0 !== $count) {
            $this->line(sprintf('Fixed %d piggy bank event(s).', $count));
        }

        $end = round(microtime(true) - $start, 2);
        $this->line(sprintf('Verified the content of %d piggy bank events in %s seconds.', $set->count(), $end));

        return 0;
    }
}
