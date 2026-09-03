<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Services\GoogleSheetSyncService;
use Illuminate\Console\Command;

class SyncTicketSheetCommand extends Command
{
    protected $signature = 'ticket:sync-sheet {id}';
    protected $description = 'Sync ticket data and photos to Google Sheet and Google Drive in background';

    public function handle(): int
    {
        $id = $this->argument('id');
        $ticket = Ticket::find($id);
        if ($ticket) {
            GoogleSheetSyncService::syncTicketToGoogleSheet($ticket);
        }
        return 0;
    }
}
