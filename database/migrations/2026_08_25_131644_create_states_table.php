<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('states', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name');
            $table->timestamps();
        });

        $states = [
            "AN" => "Andaman and Nicobar Islands",
            "AP" => "Andhra Pradesh",
            "AR" => "Arunachal Pradesh",
            "AS" => "Assam",
            "BR" => "Bihar",
            "CG" => "Chandigarh",
            "CH" => "Chhattisgarh",
            "DN" => "Dadra and Nagar Haveli",
            "DD" => "Daman and Diu",
            "DL" => "Delhi",
            "GA" => "Goa",
            "GJ" => "Gujarat",
            "HR" => "Haryana",
            "HP" => "Himachal Pradesh",
            "JK" => "Jammu and Kashmir",
            "JH" => "Jharkhand",
            "KA" => "Karnataka",
            "KL" => "Kerala",
            "LA" => "Ladakh",
            "LD" => "Lakshadweep",
            "MP" => "Madhya Pradesh",
            "MH" => "Maharashtra",
            "MN" => "Manipur",
            "ML" => "Meghalaya",
            "MZ" => "Mizoram",
            "NL" => "Nagaland",
            "OR" => "Odisha",
            "PY" => "Puducherry",
            "PB" => "Punjab",
            "RJ" => "Rajasthan",
            "SK" => "Sikkim",
            "TN" => "Tamil Nadu",
            "TS" => "Telangana",
            "TR" => "Tripura",
            "UP" => "Uttar Pradesh",
            "UK" => "Uttarakhand",
            "WB" => "West Bengal"
        ];

        $now = date('Y-m-d H:i:s');
        foreach ($states as $code => $name) {
            DB::table('states')->insert([
                'code' => $code,
                'name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('states');
    }
};

