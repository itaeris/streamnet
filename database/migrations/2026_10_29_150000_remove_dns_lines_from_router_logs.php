<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        do {
            $ids = DB::table('router_logs')
                ->where('topics', 'like', '%dns%')
                ->where(fn ($query) => $query->where('topics', 'like', '%packet%')->orWhere('message', 'like', '%query from%'))
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
