<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        do {
            $ids = DB::table('router_logs')
                ->whereIn('topics', ['dns', 'dns,packet'])
                ->where(fn ($query) => $query
                    ->where('message', 'like', 'query from%')
                    ->orWhere('message', 'like', 'done query%')
                    ->orWhere('message', 'like', 'local query%'))
                ->limit(5000)
                ->pluck('id');

            DB::table('router_logs')->whereIn('id', $ids)->delete();
        } while ($ids->isNotEmpty());
    }

    public function down(): void
    {
        //
    }
};
