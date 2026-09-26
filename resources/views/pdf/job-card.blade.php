<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>JOB_CARD_{{ $data['job_number'] }}</title>
<style>
        @page {
            size: A4 portrait;
            margin: 5mm 6mm 5mm 6mm;
        }
        body {
            font-family: Arial, Helvetica, sans-serif;
            margin: 0;
            padding: 0;
            color: #000000;
            background: #ffffff;
            font-size: 10px;
            line-height: 1.18;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        * {
            box-sizing: border-box;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .outer-table {
            border: 2px solid #000000;
            width: 100%;
        }
        .outer-table td, .outer-table th {
            border: 1px solid #000000;
            padding: 3.5px 4px;
            vertical-align: middle;
            color: #000000;
            font-size: 9.5px;
            overflow: hidden;
            word-wrap: break-word;
        }
        .b-bottom-2 {
            border-bottom: 2px solid #000000 !important;
        }
        .b-right-2 {
            border-right: 2px solid #000000 !important;
        }
        .b-none {
            border: none !important;
        }
        .p-0 {
            padding: 0 !important;
        }
        .text-center {
            text-align: center;
        }
        .text-right {
            text-align: right;
        }
        .font-bold {
            font-weight: bold;
        }
        .font-black {
            font-weight: 900;
        }
        .text-title {
            font-size: 17px;
            font-weight: 900;
            text-align: center;
            text-decoration: underline;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .job-no-title {
            font-size: 12px;
            font-weight: 900;
        }
        .job-no-value {
            font-size: 14px;
            font-weight: 900;
            text-decoration: underline;
        }
        .type-title {
            font-size: 13.5px;
            font-weight: 900;
            text-align: right;
        }
        .inner-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .inner-table td {
            border: none;
            padding: 2.5px 3px;
            font-size: 9.5px;
        }
        .note-header {
            font-weight: 900;
            font-size: 10px;
            text-decoration: underline;
            margin-bottom: 3px;
        }
        .note-body {
            font-size: 9px;
            line-height: 1.25;
            white-space: pre-wrap;
            min-height: 42px;
        }
        .proc-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .proc-table th {
            border: 1px solid #000000;
            border-bottom: 2px solid #000000;
            font-weight: 900;
            font-size: 9px;
            text-align: center;
            padding: 4px 2px;
            text-transform: uppercase;
            background-color: #ffffff;
        }
        .proc-table td {
            border: 1px solid #000000;
            font-size: 9px;
            text-align: center;
            padding: 3px 2px;
            height: 23px;
        }
        .proc-name {
            font-weight: 900;
            text-align: left !important;
            padding-left: 6px !important;
            text-transform: uppercase;
        }
        .checkbox-sq {
            display: inline-block;
            width: 11px;
            height: 11px;
            border: 1px solid #000000;
            text-align: center;
            line-height: 10px;
            font-size: 9px;
            font-weight: bold;
            margin-left: 2px;
            vertical-align: middle;
        }
        .val {
            font-weight: bold;
            margin-left: 2px;
        }
    </style>
</head>
<body>
    <table class="outer-table">
        <tbody>
            <!-- Header Row -->
            <tr>
                <td class="b-right-2 b-bottom-2" style="width: 33.33%; vertical-align: middle;">
                    <span class="job-no-title">JOB NO:</span>
                    <span class="job-no-value">{{ $data['job_number'] }}</span>
                </td>
                <td class="b-right-2 b-bottom-2 text-center" style="width: 33.33%; vertical-align: middle;">
                    @if(!empty($data['is_single_side']))
                        <div style="font-size: 9px; font-weight: bold; margin-bottom: 2px;">
                            <span>Expose <span class="checkbox-sq">{{ !empty($data['expose']) ? '✓' : '' }}</span></span>
                            <span style="margin-left: 10px;">Print & Etch <span class="checkbox-sq">{{ !empty($data['print_and_etch']) ? '✓' : '' }}</span></span>
                        </div>
                        <div class="text-title">JOB CARD</div>
                    @else
                        <div class="text-title">JOB CARD</div>
                    @endif
                </td>
                <td class="b-bottom-2 type-title" style="width: 33.33%; vertical-align: middle;">
                    {{ $data['job_type'] }}
                </td>
            </tr>

            <!-- Row 2: Dates -->
            <tr>
                <td class="b-right-2 b-bottom-2">Order Date: <span class="val">{{ $data['order_date'] }}</span></td>
                <td class="b-right-2 b-bottom-2">Launch Date: <span class="val">{{ $data['launch_date'] }}</span></td>
                <td class="b-bottom-2">Shipping Date: <span class="val">{{ $data['shipping_date'] }}</span></td>
            </tr>

            <!-- Row 3: Quantities & Min Hole -->
            <tr>
                <td class="b-right-2 b-bottom-2">ORDER QTY: <span class="val">{{ $data['order_qty'] }}</span></td>
                <td class="b-right-2 b-bottom-2">LAUNCHED: <span class="val">{{ $data['launched_qty'] }}</span></td>
                <td class="b-bottom-2 p-0">
                    <table class="inner-table">
                        <tr>
                            <td class="b-right-2" style="width: 33%;">UPS: <span class="val">{{ $data['ups'] }}</span></td>
                            <td class="b-right-2" style="width: 33%;">PANELS: <span class="val">{{ $data['panels'] }}</span></td>
                            <td style="width: 34%;">Min.Hole: <span class="val">{{ $data['min_hole'] }}</span></td>
                        </tr>
                    </table>
                </td>
            </tr>

            <!-- Row 4: Panel & Cutting Size -->
            <tr>
                <td colspan="2" class="b-right-2 b-bottom-2">
                    PANEL SIZE: <span class="val">{{ $data['panel_size'] ? (str_contains(strtoupper($data['panel_size']), 'MM') ? $data['panel_size'] : $data['panel_size'] . ' MM') : 'MM' }}</span>
                </td>
                <td class="b-bottom-2">
                    CUTTING SIZE: <span class="val">{{ $data['cutting_size'] ? (str_contains(strtoupper($data['cutting_size']), 'MM') ? $data['cutting_size'] : $data['cutting_size'] . ' MM') : 'MM' }}</span>
                </td>
            </tr>

            <!-- Row 5: Material, Thickness, Copper, Finish -->
            <tr>
                <td class="b-right-2 b-bottom-2">Material: <span class="val">{{ $data['material'] }}</span></td>
                <td class="b-right-2 b-bottom-2">Thick: <span class="val">{{ $data['thickness'] ? (str_contains(strtoupper($data['thickness']), 'MM') ? $data['thickness'] : $data['thickness'] . ' MM') : 'MM' }}</span></td>
                <td class="b-bottom-2 p-0">
                    <table class="inner-table">
                        <tr>
                            <td class="b-right-2" style="width: 50%;">Copper Thick: <span class="val">{{ $data['copper_thickness'] ? (str_contains(strtoupper($data['copper_thickness']), 'MICRON') || str_contains(strtoupper($data['copper_thickness']), 'OZ') ? $data['copper_thickness'] : $data['copper_thickness'] . ' Micron') : 'Micron' }}</span></td>
                            <td style="width: 50%;">Finish: <span class="val">{{ $data['finish'] }}</span></td>
                        </tr>
                    </table>
                </td>
            </tr>

            <!-- Row 6: Mask Colour, LP Color, LP Side -->
            <tr>
                <td class="b-right-2 b-bottom-2">Mask Colour: <span class="val">{{ $data['mask_colour'] }}</span></td>
                <td class="b-right-2 b-bottom-2">LP Color: <span class="val">{{ $data['lp_color'] }}</span></td>
                <td class="b-bottom-2">LP Side: <span class="val">{{ $data['lp_side'] }}</span></td>
            </tr>

            <!-- Row 7: Routing / V-Cut / FPT / Stage / Cutouts -->
            <tr>
                @if(!empty($data['is_single_side']))
                    <td class="b-right-2 b-bottom-2">Route: <span class="val">{{ $data['route'] }}</span></td>
                    <td class="b-right-2 b-bottom-2">V-Cut: <span class="val">{{ $data['v_cut'] }}</span></td>
                    <td class="b-bottom-2 p-0">
                        <table class="inner-table">
                            <tr>
                                <td class="b-right-2" style="width: 50%;">Shearing Cut: <span class="val">{{ $data['shearing_cut'] }}</span></td>
                                <td style="width: 50%;">Internal Cutouts Reqd.?: <span class="val">{{ $data['internal_cutouts'] }}</span></td>
                            </tr>
                        </table>
                    </td>
                @else
                    <td class="b-right-2 b-bottom-2">Route: <span class="val">{{ $data['route'] }}</span></td>
                    <td class="b-right-2 b-bottom-2">V-Cut: <span class="val">{{ $data['v_cut'] }}</span></td>
                    <td class="b-bottom-2 p-0">
                        <table class="inner-table">
                            <tr style="border-bottom: 1px solid #000000;">
                                <td class="b-right-2" style="width: 50%;">FPT Program: <span class="val">{{ $data['fpt_program'] }}</span></td>
                                <td style="width: 50%;">2<sup>nd</sup> stage reqd.?: <span class="val">{{ $data['second_stage'] }}</span></td>
                            </tr>
                            <tr>
                                <td class="b-right-2" style="width: 50%;">Copper Area: <span class="val">{{ $data['copper_area'] ? (str_contains(strtoupper($data['copper_area']), 'AMP') ? $data['copper_area'] : $data['copper_area'] . ' Amp') : 'Amp' }}</span></td>
                                <td style="width: 50%;">Internal Cutouts Reqd.?: <span class="val">{{ $data['internal_cutouts'] }}</span></td>
                            </tr>
                        </table>
                    </td>
                @endif
            </tr>

            <!-- Row 8: Notes Section -->
            <tr>
                <td colspan="2" class="b-right-2 b-bottom-2" style="vertical-align: top; height: 45px;">
                    <div class="note-header">Production Note:</div>
                    <div class="note-body">{{ $data['production_note'] ?: "• \n• " }}</div>
                </td>
                <td class="b-bottom-2" style="vertical-align: top; height: 45px;">
                    <div class="note-header">Customer Special Note:</div>
                    <div class="note-body">{{ $data['customer_note'] ?: '' }}</div>
                </td>
            </tr>

            <!-- Row 9: Final Quantities -->
            <tr>
                <td colspan="3" class="p-0 b-bottom-2">
                    <table class="inner-table text-center" style="table-layout: fixed;">
                        <thead>
                            <tr style="border-bottom: 1px solid #000000; font-weight: bold;">
                                <th style="width: 25%; border-right: 1px solid #000000; padding: 3px 2px; font-size: 9px; font-weight: bold;">Final Panel Qty.</th>
                                <th style="width: 25%; border-right: 1px solid #000000; padding: 3px 2px; font-size: 9px; font-weight: bold;">Final Board Qty.</th>
                                <th style="width: 25%; border-right: 1px solid #000000; padding: 3px 2px; font-size: 9px; font-weight: bold;">Rejected Board Qty.</th>
                                <th style="width: 25%; padding: 3px 2px; font-size: 9px; font-weight: bold;">Why Rejected?</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr style="height: 18px;">
                                <td style="border-right: 1px solid #000000; font-weight: bold;">{{ $data['final_panel_qty'] }}</td>
                                <td style="border-right: 1px solid #000000; font-weight: bold;">{{ $data['final_board_qty'] }}</td>
                                <td style="border-right: 1px solid #000000; font-weight: bold;">{{ $data['rejected_board_qty'] }}</td>
                                <td style="font-weight: bold;">{{ $data['why_rejected'] }}</td>
                            </tr>
                        </tbody>
                    </table>
                </td>
            </tr>

            <!-- Row 10: Manufacturing Process Table -->
            <tr>
                <td colspan="3" class="p-0">
                    <table class="proc-table">
                        <thead>
                            <tr>
                                <th style="width: 26%; text-align: left; padding-left: 6px;">PROCESS</th>
                                <th style="width: 8%;">IN</th>
                                <th style="width: 14%;">PANEL QTY</th>
                                <th style="width: 8%;">OUT</th>
                                <th style="width: 14%;">PANEL QTY</th>
                                <th style="width: 8%;">Q.C</th>
                                <th style="width: 10%;">SIGN</th>
                                <th style="width: 12%;">REMARK</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($data['processes'] as $proc)
                                <tr>
                                    <td class="proc-name">{{ $proc['process'] }}</td>
                                    <td>{{ $proc['in'] }}</td>
                                    <td>{{ $proc['panel_qty_in'] }}</td>
                                    <td>{{ $proc['out'] }}</td>
                                    <td>{{ $proc['panel_qty_out'] }}</td>
                                    <td>{{ $proc['qc'] }}</td>
                                    <td>{{ $proc['sign'] }}</td>
                                    <td>{{ $proc['remark'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </td>
            </tr>
        </tbody>
    </table>
</body>
</html>
