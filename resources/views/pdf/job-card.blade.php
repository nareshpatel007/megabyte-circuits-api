<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>JOB_CARD_{{ $data['job_number'] }}</title>
    <style>
        @page {
            size: 216mm 279mm portrait;
            margin: 4mm 5mm 4mm 5mm;
        }
        body {
            font-family: "Times New Roman", Times, Georgia, serif;
            margin: 0;
            padding: 0;
            color: #000000;
            background: #ffffff;
            font-size: 11pt;
            line-height: 1.25;
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
            border: 2.5px solid #000000;
            width: 100%;
        }
        .outer-table td, .outer-table th {
            border: 1px solid #000000;
            padding: 4px 5px;
            vertical-align: middle;
            color: #000000;
            font-size: 10.5pt;
            overflow: hidden;
            word-wrap: break-word;
        }
        .b-bottom-2 {
            border-bottom: 2.5px solid #000000 !important;
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
        .text-title {
            font-size: 22pt;
            font-weight: bold;
            text-align: center;
            letter-spacing: 0.5px;
            font-family: "Times New Roman", Times, serif;
        }
        .job-no-title {
            font-size: 18pt;
            font-weight: bold;
            font-family: "Times New Roman", Times, serif;
        }
        .job-no-value {
            font-size: 18pt;
            font-weight: bold;
            text-decoration: underline;
            margin-left: 4px;
        }
        .type-title {
            font-size: 18pt;
            font-weight: bold;
            font-family: "Times New Roman", Times, serif;
        }
        .inner-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .inner-table td {
            border: none;
            padding: 4px 5px;
            font-size: 10.5pt;
        }
        .note-header {
            font-weight: bold;
            font-size: 11.5pt;
            text-decoration: underline;
            margin-bottom: 5px;
        }
        .note-body {
            font-size: 10.5pt;
            line-height: 1.35;
            white-space: pre-wrap;
            min-height: 80px;
        }
        .proc-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .proc-table th {
            border: 1px solid #000000;
            border-bottom: 2px solid #000000;
            font-weight: bold;
            font-size: 11pt;
            text-align: center;
            padding: 6px 2px;
            text-transform: uppercase;
            background-color: #ffffff;
            font-family: "Times New Roman", Times, serif;
        }
        .proc-table td {
            border: 1px solid #000000;
            font-size: 10.5pt;
            text-align: center;
            padding: 4px 2px;
        }
        .proc-single td {
            height: 33px;
            line-height: 25px;
        }
        .proc-multi td {
            height: 27px;
            line-height: 21px;
        }
        .proc-name {
            font-weight: bold;
            text-align: left !important;
            padding-left: 8px !important;
        }
        .checkbox-sq {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 1.5px solid #000000;
            text-align: center;
            line-height: 12px;
            font-size: 11pt;
            font-weight: bold;
            margin-left: 4px;
            vertical-align: middle;
            font-family: DejaVu Sans, Arial, sans-serif;
        }
        .val {
            font-weight: normal;
            margin-left: 3px;
        }
        .label-bold {
            font-weight: bold;
        }
    </style>
</head>
<body>
    <table class="outer-table">
        <tbody>
            <!-- Header Row 1: JOB NO & Checkboxes -->
            <tr>
                <td colspan="2" class="b-right-2" style="width: 50%; padding: 5px 8px;">
                    <span class="job-no-title">JOB NO:</span>
                    <span class="job-no-value">{{ $data['job_number'] }}</span>
                </td>
                <td class="text-right" style="width: 50%; padding: 5px 10px;">
                    <span style="font-size: 13pt; font-weight: bold;">
                        Expose <span class="checkbox-sq">@if(!empty($data['expose']))✓@endif</span>
                    </span>
                    <span style="font-size: 13pt; font-weight: bold; margin-left: 18px;">
                        Print & Etch <span class="checkbox-sq">@if(!empty($data['print_and_etch']))✓@endif</span>
                    </span>
                </td>
            </tr>

            <!-- Header Row 2: Board Type & JOB CARD Title -->
            <tr class="b-bottom-2">
                <td class="b-right-2 type-title" style="width: 25%; padding: 5px 8px;">
                    {{ $data['job_type'] }}
                </td>
                <td colspan="2" class="text-title" style="width: 75%; padding: 5px;">
                    JOB CARD
                </td>
            </tr>

            <!-- Row 3: Dates -->
            <tr>
                <td colspan="3" class="p-0 b-bottom-2">
                    <table class="inner-table" style="width: 100%; border-collapse: collapse;">
                        <tr>
                            <td class="label-bold b-right-2" style="width: 14%; border-right: 1px solid #000;">Order Date</td>
                            <td class="text-center b-right-2" style="width: 19%; border-right: 1px solid #000;">{{ $data['order_date'] }}</td>
                            <td class="label-bold b-right-2" style="width: 14%; border-right: 1px solid #000;">Launch Date</td>
                            <td class="text-center b-right-2" style="width: 19%; border-right: 1px solid #000;">{{ $data['launch_date'] }}</td>
                            <td class="label-bold b-right-2" style="width: 15%; border-right: 1px solid #000;">Shipping Date:</td>
                            <td class="text-center" style="width: 19%;">{{ $data['shipping_date'] }}</td>
                        </tr>
                    </table>
                </td>
            </tr>

            <!-- Row 4: Quantities & Min Hole -->
            <tr>
                <td colspan="3" class="p-0 b-bottom-2">
                    <table class="inner-table" style="width: 100%; border-collapse: collapse;">
                        <tr>
                            <td class="label-bold b-right-2" style="width: 14%; border-right: 1px solid #000;">ORDER QTY:</td>
                            <td class="text-center b-right-2" style="width: 19%; border-right: 1px solid #000;">{{ $data['order_qty'] }}</td>
                            <td class="label-bold b-right-2" style="width: 14%; border-right: 1px solid #000;">LAUNCHED</td>
                            <td class="text-center b-right-2" style="width: 11%; border-right: 1px solid #000;">{{ $data['launched_qty'] }}</td>
                            <td class="label-bold b-right-2" style="width: 7%; border-right: 1px solid #000;">UPS:</td>
                            <td class="text-center b-right-2" style="width: 7%; border-right: 1px solid #000;">{{ $data['ups'] }}</td>
                            <td class="label-bold b-right-2" style="width: 10%; border-right: 1px solid #000;">PANELS:</td>
                            <td class="text-center b-right-2" style="width: 6%; border-right: 1px solid #000;">{{ $data['panels'] }}</td>
                            <td class="label-bold b-right-2" style="width: 10%; border-right: 1px solid #000;">Min.Hole:</td>
                            <td class="text-center" style="width: 12%;">{{ $data['min_hole'] }}</td>
                        </tr>
                    </table>
                </td>
            </tr>

            <!-- Row 5: Panel & Cutting Size -->
            <tr>
                <td colspan="3" class="p-0 b-bottom-2">
                    <table class="inner-table" style="width: 100%; border-collapse: collapse;">
                        <tr>
                            <td class="label-bold b-right-2" style="width: 16%; border-right: 1px solid #000;">PANEL SIZE:</td>
                            <td class="b-right-2" style="width: 34%; border-right: 1px solid #000;">{{ $data['panel_size'] ? (str_contains(strtoupper($data['panel_size']), 'MM') ? $data['panel_size'] : $data['panel_size'] . ' MM') : 'MM' }}</td>
                            <td class="label-bold b-right-2" style="width: 16%; border-right: 1px solid #000;">CUTTING SIZE:</td>
                            <td style="width: 34%;">{{ $data['cutting_size'] ? (str_contains(strtoupper($data['cutting_size']), 'MM') ? $data['cutting_size'] : $data['cutting_size'] . ' MM') : 'MM' }}</td>
                        </tr>
                    </table>
                </td>
            </tr>

            <!-- Row 6: Material, Thickness, Copper, Finish -->
            <tr>
                <td colspan="3" class="p-0 b-bottom-2">
                    <table class="inner-table" style="width: 100%; border-collapse: collapse;">
                        <tr>
                            <td class="label-bold b-right-2" style="width: 12%; border-right: 1px solid #000;">Material:</td>
                            <td class="b-right-2" style="width: 13%; border-right: 1px solid #000;">{{ $data['material'] }}</td>
                            <td class="label-bold b-right-2" style="width: 8%; border-right: 1px solid #000;">Thick:</td>
                            <td class="b-right-2" style="width: 12%; border-right: 1px solid #000;">{{ $data['thickness'] ? (str_contains(strtoupper($data['thickness']), 'MM') ? $data['thickness'] : $data['thickness'] . ' MM') : 'MM' }}</td>
                            <td class="label-bold b-right-2" style="width: 15%; border-right: 1px solid #000;">Copper Thick:</td>
                            <td class="b-right-2" style="width: 15%; border-right: 1px solid #000;">{{ $data['copper_thickness'] ? (str_contains(strtoupper($data['copper_thickness']), 'MICRON') || str_contains(strtoupper($data['copper_thickness']), 'OZ') ? $data['copper_thickness'] : $data['copper_thickness'] . ' Micron') : 'Micron' }}</td>
                            <td class="label-bold b-right-2" style="width: 8%; border-right: 1px solid #000;">Finish:</td>
                            <td style="width: 17%;">{{ $data['finish'] }}</td>
                        </tr>
                    </table>
                </td>
            </tr>

            <!-- Row 7: Mask Colour, LP Color, LP Side -->
            <tr>
                <td colspan="3" class="p-0 b-bottom-2">
                    <table class="inner-table" style="width: 100%; border-collapse: collapse;">
                        <tr>
                            <td class="label-bold b-right-2" style="width: 14%; border-right: 1px solid #000;">Mask Colour:</td>
                            <td class="b-right-2" style="width: 20%; border-right: 1px solid #000;">{{ $data['mask_colour'] }}</td>
                            <td class="label-bold b-right-2" style="width: 14%; border-right: 1px solid #000;">LP Color:</td>
                            <td class="b-right-2" style="width: 20%; border-right: 1px solid #000;">{{ $data['lp_color'] }}</td>
                            <td class="label-bold b-right-2" style="width: 12%; border-right: 1px solid #000;">LP Side:</td>
                            <td style="width: 20%;">{{ $data['lp_side'] }}</td>
                        </tr>
                    </table>
                </td>
            </tr>

            <!-- Row 8: Routing / V-Cut / Shearing / Cutouts -->
            <tr>
                <td colspan="3" class="p-0 b-bottom-2">
                    @if(!empty($data['is_single_side']))
                        <table class="inner-table" style="width: 100%; border-collapse: collapse;">
                            <tr>
                                <td class="label-bold b-right-2" style="width: 10%; border-right: 1px solid #000;">Route:</td>
                                <td class="b-right-2" style="width: 20%; border-right: 1px solid #000;">{{ $data['route'] }}</td>
                                <td class="label-bold b-right-2" style="width: 10%; border-right: 1px solid #000;">V-Cut:</td>
                                <td class="b-right-2" style="width: 15%; border-right: 1px solid #000;">{{ $data['v_cut'] }}</td>
                                <td class="label-bold b-right-2" style="width: 15%; border-right: 1px solid #000;">Shearing Cut:</td>
                                <td class="b-right-2" style="width: 10%; border-right: 1px solid #000;">{{ $data['shearing_cut'] }}</td>
                                <td class="label-bold text-center b-right-2" style="width: 15%; border-right: 1px solid #000;">Internal<br>Cutouts Reqd.?</td>
                                <td class="text-center" style="width: 5%;">{{ $data['internal_cutouts'] }}</td>
                            </tr>
                        </table>
                    @else
                        <table class="inner-table" style="width: 100%; border-collapse: collapse;">
                            <tr>
                                <td class="label-bold b-right-2" style="width: 10%; border-right: 1px solid #000;">Route:</td>
                                <td class="b-right-2" style="width: 20%; border-right: 1px solid #000;">{{ $data['route'] }}</td>
                                <td class="label-bold b-right-2" style="width: 10%; border-right: 1px solid #000;">V-Cut:</td>
                                <td class="b-right-2" style="width: 10%; border-right: 1px solid #000;">{{ $data['v_cut'] }}</td>
                                <td class="label-bold b-right-2" style="width: 12%; border-right: 1px solid #000;">FPT Program:</td>
                                <td class="b-right-2" style="width: 10%; border-right: 1px solid #000;">{{ $data['fpt_program'] }}</td>
                                <td class="label-bold b-right-2" style="width: 14%; border-right: 1px solid #000;">2<sup>nd</sup> stage reqd.?</td>
                                <td style="width: 14%;">{{ $data['second_stage'] }}</td>
                            </tr>
                        </table>
                    @endif
                </td>
            </tr>

            <!-- Row 9: Notes Section (Increased height) -->
            <tr>
                <td colspan="2" class="b-right-2 b-bottom-2" style="vertical-align: top; height: 85px; width: 60%;">
                    <div class="note-header">Production Note:</div>
                    <div class="note-body">{{ $data['production_note'] ?: "• \n• " }}</div>
                </td>
                <td class="b-bottom-2" style="vertical-align: top; height: 85px; width: 40%;">
                    <div class="note-header">Customer Special Note:</div>
                    <div class="note-body">{{ $data['customer_note'] ?: '' }}</div>
                </td>
            </tr>

            <!-- Row 10: Final Quantities Header & Values -->
            <tr>
                <td colspan="3" class="p-0 b-bottom-2">
                    <table class="inner-table text-center" style="table-layout: fixed; width: 100%;">
                        <thead>
                            <tr style="border-bottom: 1px solid #000000; font-weight: bold;">
                                <th style="width: 25%; border-right: 1px solid #000000; padding: 5px 2px; font-size: 10.5pt; font-weight: bold;">Final Panel Qty.</th>
                                <th style="width: 25%; border-right: 1px solid #000000; padding: 5px 2px; font-size: 10.5pt; font-weight: bold;">Final Board Qty.</th>
                                <th style="width: 25%; border-right: 1px solid #000000; padding: 5px 2px; font-size: 10.5pt; font-weight: bold;">Rejected Board Qty.</th>
                                <th style="width: 25%; padding: 5px 2px; font-size: 10.5pt; font-weight: bold;">Why Rejected?</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr style="height: 25px;">
                                <td style="border-right: 1px solid #000000; font-weight: bold;">{{ $data['final_panel_qty'] }}</td>
                                <td style="border-right: 1px solid #000000; font-weight: bold;">{{ $data['final_board_qty'] }}</td>
                                <td style="border-right: 1px solid #000000; font-weight: bold;">{{ $data['rejected_board_qty'] }}</td>
                                <td style="font-weight: bold;">{{ $data['why_rejected'] }}</td>
                            </tr>
                        </tbody>
                    </table>
                </td>
            </tr>

            <!-- Row 11: Manufacturing Process Table -->
            <tr>
                <td colspan="3" class="p-0">
                    <table class="proc-table {{ !empty($data['is_single_side']) ? 'proc-single' : 'proc-multi' }}">
                        <thead>
                            <tr style="height: 25px;">
                                <th style="width: 24%; text-align: center;">PROCESS</th>
                                <th style="width: 8%;">IN</th>
                                <th style="width: 14%;">PANEL QTY</th>
                                <th style="width: 8%;">OUT</th>
                                <th style="width: 14%;">PANEL QTY</th>
                                <th style="width: 8%;">Q.C</th>
                                <th style="width: 10%;">SIGN</th>
                                <th style="width: 14%;">REMARK</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php
                                $rowHNum = !empty($data['is_single_side']) ? '33' : '27';
                            @endphp
                            @foreach($data['processes'] as $proc)
                                <tr height="{{ $rowHNum }}">
                                    <td class="proc-name" height="{{ $rowHNum }}">{{ $proc['process'] }}</td>
                                    <td height="{{ $rowHNum }}">{{ $proc['in'] }}</td>
                                    <td height="{{ $rowHNum }}">{{ $proc['panel_qty_in'] }}</td>
                                    <td height="{{ $rowHNum }}">{{ $proc['out'] }}</td>
                                    <td height="{{ $rowHNum }}">{{ $proc['panel_qty_out'] }}</td>
                                    <td height="{{ $rowHNum }}">{{ $proc['qc'] }}</td>
                                    <td height="{{ $rowHNum }}">{{ $proc['sign'] }}</td>
                                    <td height="{{ $rowHNum }}">{{ $proc['remark'] }}</td>
                                </tr>
                            @endforeach
                            <!-- Extra Summary Row matching reference PDF -->
                            <tr height="28">
                                <td class="proc-name" height="28">&nbsp;</td>
                                <td height="28">&nbsp;</td>
                                <td height="28">&nbsp;</td>
                                <td height="28">&nbsp;</td>
                                <td height="28">&nbsp;</td>
                                <td height="28">&nbsp;</td>
                                <td height="28">&nbsp;</td>
                                <td height="28">&nbsp;</td>
                            </tr>
                        </tbody>
                    </table>
                </td>
            </tr>
        </tbody>
    </table>
</body>
</html>
