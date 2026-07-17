<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PcbOrder extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        // Basic PCB Specifications
        'base_material',
        'layers',
        'width',
        'height',
        'unit',
        'qty',
        'product_type',
        'different_design',
        
        // PCB Specifications
        'thickness',
        'pcb_color',
        'silkscreen',
        'material_type',
        'surface_finish',
        
        // High-spec Options
        'copper_weight',
        'via_covering',
        'via_plating',
        'min_hole',
        'tolerance',
        'confirm_file',
        'mark_on_pcb',
        'elec_test',
        'gold_fingers',
        'castellated',
        'edge_plating',
        'blind_slots',
        'ul_marking',
        'humidity',
        
        // Advanced Options
        'kelvin_test',
        'paper_between',
        'appearance_quality',
        'silkscreen_tech',
        'inspection_report',
        'pcb_remark',
        
        // Additional Options
        'assembly_on',
        'stencil_on',
        'build_time',
        
        // Customer Information
        'board_name',
        'user_mobile',
        'user_email',
        'gst_number',
        'customer_name',
        'billing_address',
        'shipping_address',
        
        // Pricing Information
        'lead_time_days',
        'unit_price',
        'order_value',
        'delivery_date',
        'total_area_sqm',
        
        // File Upload
        'gerber_file_url',
        'gerber_file_name',
        'gerber_file_size',
        
        // Order Status
        'status',
        'order_number',
        
        // Metadata
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'width' => 'decimal:2',
        'height' => 'decimal:2',
        'qty' => 'integer',
        'different_design' => 'integer',
        'layers' => 'integer',
        'lead_time_days' => 'integer',
        'unit_price' => 'decimal:2',
        'order_value' => 'decimal:2',
        'total_area_sqm' => 'decimal:4',
        'assembly_on' => 'boolean',
        'stencil_on' => 'boolean',
        'delivery_date' => 'date',
    ];
}
