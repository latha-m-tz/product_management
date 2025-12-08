<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up()
    {
        Schema::table('service_vci_items', function (Blueprint $table) {
            $table->string('vci_serial_no')->nullable()->change();
        });
    }

    public function down()
    {
        Schema::table('service_vci_items', function (Blueprint $table) {
            $table->string('vci_serial_no')->nullable(false)->change();
        });
    }
};
