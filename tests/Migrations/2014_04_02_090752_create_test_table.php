<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTestTable extends Migration
{
    /**
     * The table name
     *
     * @var string
     */
    private $table = 'tests';

    /**
     * Run the migrations
     *
     * @return void
     */
    public function up()
    {
        Schema::create($this->table, function(Blueprint $t)
        {
            $t->increments('id')->unsigned();
            $t->string('name', 50)->nullable();
            $t->string('description', 100)->nullable();
            $t->string('password', 200)->nullable();
            $t->timestamps();
        });
    }

    /**
     * Reverse the migrations
     *
     * @return void
     */
    public function down()
    {
        Schema::drop($this->table);
    }
}
