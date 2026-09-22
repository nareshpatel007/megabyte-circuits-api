<!-- Google Fonts Inter & FontAwesome -->
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
    /* Scoped Modern PCB Order Form Styles */
    .pcb-wrapper {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        color: #1e293b;
        background-color: #f8fafc;
        padding: 24px 16px;
        border-radius: 20px;
    }
    
    .pcb-wrapper *, .pcb-wrapper *::before, .pcb-wrapper *::after {
        box-sizing: border-box;
    }

    .pcb-header {
        text-align: center;
        margin-bottom: 32px;
    }

    .pcb-badge-top {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 14px;
        background: #ecfdf5;
        border: 1px solid #a7f3d0;
        color: #047857;
        font-size: 12px;
        font-weight: 700;
        border-radius: 50px;
        margin-bottom: 12px;
        letter-spacing: 0.5px;
        text-transform: uppercase;
    }

    .pcb-header h2 {
        font-size: 32px;
        font-weight: 800;
        color: #0f172a;
        margin: 0;
        letter-spacing: -0.5px;
    }

    .pcb-header h2 span {
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }

    .pcb-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 20px;
        padding: 28px;
        box-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.05);
        margin-bottom: 24px;
        transition: all 0.3s ease;
    }

    .pcb-card:hover {
        box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.08);
    }

    .pcb-card-title {
        font-size: 16px;
        font-weight: 700;
        color: #0f172a;
        margin-top: 0;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
        padding-bottom: 12px;
        border-bottom: 2px solid #f1f5f9;
    }

    .pcb-card-title i {
        color: #10b981;
        font-size: 18px;
    }

    .pcb-wrapper .form-group {
        margin-bottom: 20px;
    }

    .pcb-wrapper label.control-label {
        display: block;
        font-size: 13px;
        font-weight: 600;
        color: #334155;
        margin-bottom: 8px;
    }

    .pcb-wrapper label.control-label i {
        color: #94a3b8;
        cursor: pointer;
        margin-left: 4px;
        transition: color 0.2s;
    }

    .pcb-wrapper label.control-label i:hover {
        color: #10b981;
    }

    .pcb-wrapper .form-control {
        width: 100%;
        height: 44px;
        padding: 10px 14px;
        font-size: 14px;
        font-weight: 500;
        color: #0f172a;
        background-color: #f8fafc;
        border: 1.5px solid #e2e8f0;
        border-radius: 10px;
        transition: all 0.2s ease-in-out;
    }

    .pcb-wrapper textarea.form-control {
        height: auto;
        min-height: 80px;
        padding: 12px 14px;
    }

    .pcb-wrapper .form-control:focus {
        background-color: #ffffff;
        border-color: #10b981;
        outline: none;
        box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.12);
    }

    .pcb-wrapper select.form-control {
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%2064748b'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 14px center;
        background-size: 16px;
        padding-right: 36px;
        cursor: pointer;
    }

    .pcb-wrapper input[type="file"].form-control {
        padding: 8px 12px;
        font-size: 13px;
        cursor: pointer;
    }

    /* Lead Time Table / Card Styling */
    .lead-time-container {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 16px;
        margin-bottom: 24px;
    }

    .lead-time-header-row {
        display: grid;
        grid-template-columns: 1.2fr 1fr 1fr 1fr;
        gap: 12px;
        padding: 8px 12px;
        font-size: 12px;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 1.5px solid #e2e8f0;
        margin-bottom: 12px;
    }

    .lead-time-row {
        display: grid;
        grid-template-columns: 1.2fr 1fr 1fr 1fr;
        gap: 12px;
        align-items: center;
        padding: 8px 12px;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        margin-bottom: 10px;
        transition: all 0.2s ease;
    }

    .lead-time-row:hover {
        border-color: #10b981;
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.08);
    }

    .lead-time-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 13px;
        font-weight: 700;
        color: #0f172a;
    }

    .lead-time-badge i {
        color: #10b981;
    }

    .lead-time-row .form-control {
        height: 38px;
        font-size: 13px;
        background-color: #f1f5f9;
        border-color: #cbd5e1;
        font-weight: 700;
    }

    .btn-submit-lead {
        height: 38px;
        width: 100%;
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        color: #ffffff;
        border: none;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        box-shadow: 0 4px 10px rgba(16, 185, 129, 0.25);
    }

    .btn-submit-lead:hover {
        background: linear-gradient(135deg, #059669 0%, #047857 100%);
        transform: translateY(-1px);
        box-shadow: 0 6px 15px rgba(16, 185, 129, 0.35);
    }

    .btn-submit-lead:active {
        transform: translateY(0);
    }

    .area-badge-box {
        background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
        color: #ffffff;
        padding: 16px 20px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 24px;
        box-shadow: 0 8px 20px rgba(15, 23, 42, 0.15);
    }

    .area-badge-box label {
        color: #94a3b8;
        font-size: 13px;
        font-weight: 600;
        margin: 0;
    }

    .area-badge-box .area-input-wrapper {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .area-badge-box input {
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.2);
        color: #34d399;
        font-size: 18px;
        font-weight: 800;
        text-align: right;
        width: 110px;
        height: 38px;
        border-radius: 8px;
        padding: 0 10px;
    }

    .area-badge-box span {
        color: #e2e8f0;
        font-weight: 700;
        font-size: 14px;
    }

    .progressBlock {
        margin-top: 20px;
    }

    .progress {
        height: 10px;
        background-color: #e2e8f0;
        border-radius: 50px;
        overflow: hidden;
    }

    .progress-bar {
        height: 100%;
        background: linear-gradient(90deg, #10b981, #34d399);
        width: 0%;
        transition: width 0.3s ease;
    }

    @media (max-width: 768px) {
        .pcb-card {
            padding: 20px;
        }

        .lead-time-header-row {
            display: none;
        }

        .lead-time-row {
            grid-template-columns: 1fr;
            gap: 8px;
            padding: 14px;
        }

        .btn-submit-lead {
            height: 42px;
            font-size: 14px;
        }
    }
</style>

<div class="pcb-wrapper">
    <div class="pcb-header">
        <div class="pcb-badge-top">
            <i class="fas fa-bolt"></i> Instant Online Calculator
        </div>
        <h2>PCB <span>Order Form</span></h2>
    </div>

    <form id="orderForm" action="#" method="POST" enctype="multipart/form-data">
        <div class="row">
            <!-- Left Column: Board Specs -->
            <div class="col-lg-6 col-md-6 col-sm-12">
                <div class="pcb-card">
                    <div class="pcb-card-title">
                        <i class="fas fa-microchip"></i> 1. Board Specifications
                    </div>

                    <div class="form-group">
                        <label class="control-label" for="boardName">
                            Board Name: <i class="fas fa-question-circle" data-toggle="tooltip" title="Enter a descriptive name for your PCB design."></i>
                        </label>
                        <input class="form-control" type="text" id="boardName" name="boardName" placeholder="e.g. Main Controller V2">
                    </div>

                    <div class="row">
                        <div class="col-lg-6 col-md-6 col-sm-12">
                            <div class="form-group">
                                <label class="control-label" for="userMobile">
                                    Mobile Number: <i class="fas fa-question-circle" data-toggle="tooltip" title="Enter your 10-digit mobile number."></i>
                                </label>
                                <input class="form-control" type="tel" id="userMobile" name="userMobile" placeholder="10-digit Mobile" pattern="[0-9]{10}">
                            </div>
                        </div>
                        <div class="col-lg-6 col-md-6 col-sm-12">
                            <div class="form-group">
                                <label class="control-label" for="userEmail">
                                    Email Address: <i class="fas fa-question-circle" data-toggle="tooltip" title="Provide a valid email address."></i>
                                </label>
                                <input class="form-control" type="email" id="userEmail" name="userEmail" placeholder="name@domain.com" required="">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="control-label" for="length">
                            Board Size (mm): <i class="fas fa-question-circle" data-toggle="tooltip" title="Specify length & width in millimeters."></i>
                        </label>
                        <div class="row">
                            <div class="col-6">
                                <input class="form-control" type="number" id="length" name="length" placeholder="Length (mm)" min="20" required="">
                            </div>
                            <div class="col-6">
                                <input class="form-control" type="number" id="width" name="width" placeholder="Width (mm)" min="20" required="">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-lg-6 col-md-6 col-sm-12">
                            <div class="form-group">
                                <label class="control-label" for="quantity">
                                    Quantity (Pcs): <i class="fas fa-question-circle" data-toggle="tooltip" title="Enter required PCB quantity (min 3)."></i>
                                </label>
                                <input class="form-control" type="number" id="quantity" name="quantity" placeholder="Quantity" min="3" value="3" required="" />
                            </div>
                        </div>
                        <div class="col-lg-6 col-md-6 col-sm-12">
                            <div class="form-group">
                                <label class="control-label" for="layers">
                                    Layers: <i class="fas fa-question-circle" data-toggle="tooltip" title="Number of copper layers."></i>
                                </label>
                                <select class="form-control" id="layers" name="layers">
                                    <option value="1">1 Layer</option>
                                    <option value="2" selected>2 Layers</option>
                                    <option value="4">4 Layers</option>
                                    <option value="6">6 Layers</option>
                                    <option value="8">8 Layers</option>
                                    <option value="10">10 Layers</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pcb-card">
                    <div class="pcb-card-title">
                        <i class="fas fa-sliders-h"></i> 2. Technical Parameters
                    </div>

                    <div class="row">
                        <div class="col-lg-6 col-md-6 col-sm-12">
                            <div class="form-group">
                                <label class="control-label" for="material">Material:</label>
                                <select class="form-control" id="material" name="material">
                                    <option value="FR4">FR-4 (Standard)</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-lg-6 col-md-6 col-sm-12">
                            <div class="form-group">
                                <label class="control-label" for="thickness">Thickness (mm):</label>
                                <select class="form-control" id="thickness" name="thickness">
                                    <option value="1.6" selected>1.6 mm</option>
                                    <option value="0.6">0.6 mm</option>
                                    <option value="0.8">0.8 mm</option>
                                    <option value="1.0">1.0 mm</option>
                                    <option value="1.2">1.2 mm</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-lg-6 col-md-6 col-sm-12">
                            <div class="form-group">
                                <label class="control-label" for="solderMask">Solder Mask Color:</label>
                                <select class="form-control" id="solderMask" name="solderMask">
                                    <option value="Green" selected>Green</option>
                                    <option value="Red">Red</option>
                                    <option value="Blue">Blue</option>
                                    <option value="Black">Black</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-lg-6 col-md-6 col-sm-12">
                            <div class="form-group">
                                <label class="control-label" for="surfaceFinish">Surface Finish:</label>
                                <select class="form-control" id="surfaceFinish" name="surfaceFinish">
                                    <option value="HASL(Leaded)" selected>HASL (Leaded)</option>
                                    <option value="Roller Tin">Roller Tin</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-lg-6 col-md-6 col-sm-12">
                            <div class="form-group">
                                <label class="control-label" for="copperWeight">Copper Weight:</label>
                                <select class="form-control" id="copperWeight" name="copperWeight">
                                    <option value="1oz" selected>1 oz</option>
                                    <option value="2oz">2 oz</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-lg-6 col-md-6 col-sm-12">
                            <div class="form-group">
                                <label class="control-label" for="fileUpload">Upload Gerber File:</label>
                                <input class="form-control" type="file" id="fileUpload" name="files" multiple="" accept=".gerber,.dxf,.zip,.rar">
                            </div>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="control-label" for="remarks">Order Remarks:</label>
                        <input class="form-control" type="text" id="remarks" name="remarks" placeholder="Special requirements, impedance specs, etc.">
                    </div>
                </div>
            </div>

            <!-- Right Column: Lead Time Matrix & Customer Details -->
            <div class="col-lg-6 col-md-6 col-sm-12">
                <div class="pcb-card">
                    <div class="pcb-card-title">
                        <i class="fas fa-clock"></i> 3. Lead Time & Instant Pricing
                    </div>

                    <div class="area-badge-box">
                        <label><i class="fas fa-ruler-combined me-1"></i> Total Calculated Area:</label>
                        <div class="area-input-wrapper">
                            <input type="text" id="totalAreaInSqM" name="totalSqMeter" placeholder="0.00" readonly>
                            <span>m²</span>
                        </div>
                    </div>

                    <div class="lead-time-container">
                        <div class="lead-time-header-row">
                            <div>Turnaround</div>
                            <div>Unit Price</div>
                            <div>Order Value</div>
                            <div>Action</div>
                        </div>

                        <!-- 1 Day -->
                        <div class="form-group lead-time-row">
                            <div class="lead-time-badge"><i class="fas fa-bolt"></i> 1 Day (Rush)</div>
                            <div><input class="form-control" type="text" id="unitPrice1" placeholder="₹0.00" readonly></div>
                            <div><input class="form-control" type="text" id="orderValue1" placeholder="₹0.00" readonly></div>
                            <div><button class="btn-submit-lead" type="button" id="submit1"><i class="fas fa-paper-plane"></i> Order</button></div>
                        </div>

                        <!-- 3 Day -->
                        <div class="form-group lead-time-row">
                            <div class="lead-time-badge"><i class="fas fa-stopwatch"></i> 3 Days</div>
                            <div><input class="form-control" type="text" id="unitPrice3" placeholder="₹0.00" readonly></div>
                            <div><input class="form-control" type="text" id="orderValue3" placeholder="₹0.00" readonly></div>
                            <div><button class="btn-submit-lead" type="button" id="submit3"><i class="fas fa-paper-plane"></i> Order</button></div>
                        </div>

                        <!-- 5 Day -->
                        <div class="form-group lead-time-row">
                            <div class="lead-time-badge"><i class="fas fa-calendar-day"></i> 5 Days</div>
                            <div><input class="form-control" type="text" id="unitPrice5" placeholder="₹0.00" readonly></div>
                            <div><input class="form-control" type="text" id="orderValue5" placeholder="₹0.00" readonly></div>
                            <div><button class="btn-submit-lead" type="button" id="submit5"><i class="fas fa-paper-plane"></i> Order</button></div>
                        </div>

                        <!-- 7 Day -->
                        <div class="form-group lead-time-row">
                            <div class="lead-time-badge"><i class="fas fa-calendar-week"></i> 7 Days</div>
                            <div><input class="form-control" type="text" id="unitPrice7" placeholder="₹0.00" readonly></div>
                            <div><input class="form-control" type="text" id="orderValue7" placeholder="₹0.00" readonly></div>
                            <div><button class="btn-submit-lead" type="button" id="submit7"><i class="fas fa-paper-plane"></i> Order</button></div>
                        </div>

                        <!-- 10 Day -->
                        <div class="form-group lead-time-row">
                            <div class="lead-time-badge"><i class="fas fa-calendar-alt"></i> 10 Days</div>
                            <div><input class="form-control" type="text" id="unitPrice10" placeholder="₹0.00" readonly></div>
                            <div><input class="form-control" type="text" id="orderValue10" placeholder="₹0.00" readonly></div>
                            <div><button class="btn-submit-lead" type="button" id="submit10"><i class="fas fa-paper-plane"></i> Order</button></div>
                        </div>

                        <!-- 20 Day -->
                        <div class="form-group lead-time-row">
                            <div class="lead-time-badge"><i class="fas fa-calendar-check"></i> 20 Days</div>
                            <div><input class="form-control" type="text" id="unitPrice20" placeholder="₹0.00" readonly></div>
                            <div><input class="form-control" type="text" id="orderValue20" placeholder="₹0.00" readonly></div>
                            <div><button class="btn-submit-lead" type="button" id="submit20"><i class="fas fa-paper-plane"></i> Order</button></div>
                        </div>
                    </div>
                </div>

                <div class="pcb-card">
                    <div class="pcb-card-title">
                        <i class="fas fa-truck-loading"></i> 4. Shipping & Billing Info
                    </div>

                    <div class="row">
                        <div class="col-lg-6 col-md-6 col-sm-12">
                            <div class="form-group">
                                <label class="control-label" for="customerName">Customer Name:</label>
                                <input class="form-control" type="text" id="customerName" name="customerName" placeholder="Full Name or Company">
                            </div>
                        </div>
                        <div class="col-lg-6 col-md-6 col-sm-12">
                            <div class="form-group">
                                <label class="control-label" for="gstNumber">GST Number (Optional):</label>
                                <input class="form-control" type="text" id="gstNumber" name="gstNumber" placeholder="22AAAAA0000A1Z5">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-lg-6 col-md-6 col-sm-12">
                            <div class="form-group">
                                <label class="control-label" for="billingAddress">Billing Address:</label>
                                <textarea class="form-control" rows="3" id="billingAddress" name="billingAddress" placeholder="Street Address, City, Pincode"></textarea>
                            </div>
                        </div>
                        <div class="col-lg-6 col-md-6 col-sm-12">
                            <div class="form-group">
                                <label class="control-label" for="shippingAddress">Shipping Address:</label>
                                <textarea class="form-control" rows="3" id="shippingAddress" name="shippingAddress" placeholder="Street Address, City, Pincode"></textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="progressBlock">
            <div class="progress" id="uploadProgress">
                <div class="progress-bar" id="progressBar"></div>
            </div>
        </div>
    </form>
</div>

<script>
    document.getElementById('layers').addEventListener('change', validateBoardSize);
    document.getElementById('length').addEventListener('blur', validateBoardSize);
    document.getElementById('width').addEventListener('blur', validateBoardSize);

    function validateBoardSize() {
        const layers = parseInt(document.getElementById('layers').value);
        let length = parseFloat(document.getElementById('length').value);
        let width = parseFloat(document.getElementById('width').value);

        let minLength = 20;
        let minWidth = 20;
        let maxLength, maxWidth;

        if (layers === 1) {
            maxLength = 400;
            maxWidth = 400;
        } else if (layers === 2) {
            maxLength = 300;
            maxWidth = 300;
        } else if ([4, 6, 8, 10].includes(layers)) {
            maxLength = 400;
            maxWidth = 500;
        }
        if (!isNaN(length) && length < minLength) {
            length = minLength;
            document.getElementById('length').value = length;
        }
        if (!isNaN(width) && width < minWidth) {
            width = minWidth;
            document.getElementById('width').value = width;
        }

        if (!isNaN(length) && !isNaN(width) && (length > maxLength || width > maxWidth)) {
            showCustomAlert(`For ${layers}-layer boards, the board size must be between ${minLength}mm x ${minWidth}mm and ${maxLength}mm x ${maxWidth}mm.`);
            document.getElementById('length').value = '';
            document.getElementById('width').value = '';
        }

        calculatePrices();
    }

    function showCustomAlert(message) {
        const alertBox = document.createElement('div');
        alertBox.style.position = 'fixed';
        alertBox.style.top = '20px';
        alertBox.style.right = '20px';
        alertBox.style.padding = '16px 24px';
        alertBox.style.background = 'linear-gradient(135deg, #ef4444, #dc2626)';
        alertBox.style.color = '#fff';
        alertBox.style.borderRadius = '14px';
        alertBox.style.boxShadow = '0 10px 25px rgba(239, 68, 68, 0.3)';
        alertBox.style.fontSize = '14px';
        alertBox.style.fontWeight = '600';
        alertBox.style.display = 'flex';
        alertBox.style.justifyContent = 'space-between';
        alertBox.style.alignItems = 'center';
        alertBox.style.maxWidth = '360px';
        alertBox.style.zIndex = '10000';

        const alertMessage = document.createElement('span');
        alertMessage.innerText = message;

        const closeBtn = document.createElement('span');
        closeBtn.innerText = '✕';
        closeBtn.style.marginLeft = '12px';
        closeBtn.style.cursor = 'pointer';
        closeBtn.style.fontWeight = 'bold';
        closeBtn.style.fontSize = '16px';
        closeBtn.style.opacity = '0.8';

        closeBtn.addEventListener('click', () => alertBox.remove());

        alertBox.appendChild(alertMessage);
        alertBox.appendChild(closeBtn);
        document.body.appendChild(alertBox);

        setTimeout(() => {
            if (document.body.contains(alertBox)) {
                alertBox.remove();
            }
        }, 7000);
    }
        
    const AREA_CONVERSION_FACTOR = 1000000; // mm^2 to m^2
    const CM_CONVERSION_FACTOR = 10000; // m^2 to cm^2
    const MIN_ORDER_QUANTITY = 3;

    function normalizeQuantity(updateField = false) {
        const quantityInput = document.getElementById('quantity');
        let quantity = parseInt(quantityInput.value, 10);

        if (isNaN(quantity) || quantity < MIN_ORDER_QUANTITY) {
            quantity = MIN_ORDER_QUANTITY;
            if (updateField) {
                quantityInput.value = MIN_ORDER_QUANTITY;
            }
        }

        return quantity;
    }
    
    function calculatePrices() {
        const layers = parseInt(document.getElementById('layers').value, 10) || 1;
        const length = parseFloat(document.getElementById('length').value) || 0;
        const width = parseFloat(document.getElementById('width').value) || 0;
        let quantity = normalizeQuantity();
        const solderMask = document.getElementById('solderMask').value;
        const copperWeight = document.getElementById('copperWeight').value;
        const thickness = parseFloat(document.getElementById('thickness').value) || 1.6;

        if (length <= 0 || width <= 0 || quantity <= 0) {
            resetPrices();
            return;
        }

        const areaPerBoard = (length * width) / AREA_CONVERSION_FACTOR;
        const totalAreaInSqM = areaPerBoard * quantity;
        
        document.getElementById('totalAreaInSqM').value = totalAreaInSqM.toFixed(2);
        
        updateLeadTimeVisibility(totalAreaInSqM, layers);

        const areaInSqCm = totalAreaInSqM * CM_CONVERSION_FACTOR;

        const fixedCosts = {
            '1': { 1: 3100, 3: 2100, 5: 1600, 7: 1500, 10: 1400 },
            '2': { 1: 8100, 3: 4100, 5: 2600, 7: 2200, 10: 1900 },
            '4': { 20: 6000 },
            '6': { 20: 7000 },
            '8': { 20: 8000 },
            '10': { 20: 9000 }
        };

        let priceTiers = getPriceTiers(solderMask, copperWeight, thickness);

        if (!priceTiers) {
            resetPrices();
            return;
        }

        let applicablePrices;
        if (totalAreaInSqM <= 0.5) {
            applicablePrices = priceTiers[layers]["0.5 or less"];
        } else if (totalAreaInSqM <= 1) {
            applicablePrices = priceTiers[layers]["0.51 to 1"];
        } else if (totalAreaInSqM <= 2) {
            applicablePrices = priceTiers[layers]["1.01 to 2"];
        } else if (totalAreaInSqM <= 3) {
            applicablePrices = priceTiers[layers]["2.01 to 3"];
        } else if (totalAreaInSqM <= 9.99) {
            applicablePrices = priceTiers[layers]["3.01 to 9.99"];
        } else {
            resetPrices();
            return;
        }

        if (applicablePrices) {
            updateLeadTimePrices(1, areaInSqCm, applicablePrices[0], quantity, fixedCosts[layers]?.[1] || 0);
            updateLeadTimePrices(3, areaInSqCm, applicablePrices[1], quantity, fixedCosts[layers]?.[3] || 0);
            updateLeadTimePrices(5, areaInSqCm, applicablePrices[2], quantity, fixedCosts[layers]?.[5] || 0);
            updateLeadTimePrices(7, areaInSqCm, applicablePrices[3], quantity, fixedCosts[layers]?.[7] || 0);
            updateLeadTimePrices(10, areaInSqCm, applicablePrices[4], quantity, fixedCosts[layers]?.[10] || 0);
            updateLeadTimePrices(20, areaInSqCm, applicablePrices[0], quantity, fixedCosts[layers]?.[20] || 0);
        }

        updateLeadTimeVisibility(totalAreaInSqM, layers);
        hideLeadTimesBasedOnArea(totalAreaInSqM, layers);
    }

    function updateLeadTimePrices(days, areaInSqCm, costPerSqCm, quantity, fixedCost) {
        if (!costPerSqCm || !document.getElementById(`unitPrice${days}`)) return;
        const variableCost = areaInSqCm * costPerSqCm;
        const totalCost = fixedCost + variableCost;
        const unitPrice = totalCost / quantity;

        document.getElementById(`unitPrice${days}`).value = '₹' + unitPrice.toFixed(2);
        document.getElementById(`orderValue${days}`).value = '₹' + totalCost.toFixed(2);
    }

    function resetPrices() {
        const days = [1, 3, 5, 7, 10, 20];
        days.forEach(day => {
            const up = document.getElementById(`unitPrice${day}`);
            const ov = document.getElementById(`orderValue${day}`);
            if (up) up.value = "";
            if (ov) ov.value = "";
        });
    }

    function getPriceTiers(mask, weight, thickness) {
        const tiers = {
            'Green': {
                '1oz': {
                    1.6: getStandardPrices(),
                    'other': getGreenMask1ozOtherThicknessPrices()
                },
                '2oz': {
                    1.6: getGreenMask2ozPrices(),
                    'other': getGreenMask2ozOtherThicknessPrices()
                }
            },
            'Other': {
                '1oz': {
                    1.6: getOtherMask1ozPrices(),
                    'other': getOtherMask1ozOtherThicknessPrices()
                },
                '2oz': {
                    1.6: getOtherMask2ozPrices(),
                    'other': getOtherMask2ozOtherThicknessPrices()
                }
            }
        };

        const maskKey = mask === 'Green' ? 'Green' : 'Other';
        return tiers[maskKey]?.[weight]?.[thickness] ?? tiers[maskKey]?.[weight]?.['other'] ?? tiers['Other']?.[weight]?.['other'] ?? null;
    }

    function getStandardPrices() {
        return {
            '1': {
                "0.5 or less": [4.62, 3.08, 2.31, 1.925, 1.54],
                "0.51 to 1": [4.62, 3.08, 2.31, 1.925, 1.54],
                "1.01 to 2": [3.08, 1.54, 1.386, 1.078, 0.77],
                "2.01 to 3": [3.08, 1.54, 1.386, 0.886, 0.539],
                "3.01 to 9.99": [0, 1.54, 1.155, 0.847, 0.539]
            },
            '2': {
                "0.5 or less": [5.28, 4.62, 3.3, 2.64, 1.98],
                "0.51 to 1": [5.28, 3.96, 2.64, 2.31, 1.98],
                "1.01 to 2": [0, 2.64, 2.31, 1.816, 1.32],
                "2.01 to 3": [0, 0, 1.848, 1.584, 1.32],
                "3.01 to 9.99": [0, 0, 0, 1.518, 1.32]
            },
            '4': {
                "0.5 or less": [7, 5.6, 4.2, 3.5, 2.8],
                "0.51 to 1": [7, 5.6, 4.2, 3.5, 2.8],
                "1.01 to 2": [4.2, 2.8, 2.52, 2.1, 1.68],
                "2.01 to 3": [4.2, 2.8, 2.1, 1.68, 1.4],
                "3.01 to 9.99": [4.2, 2.8, 2.1, 1.68, 1.4]
            },
            '6': {
                "0.5 or less": [9.8, 8.4, 6.3, 4.9, 4.2],
                "0.51 to 1": [9.8, 8.4, 6.3, 4.9, 4.2],
                "1.01 to 2": [7, 5.6, 4.9, 4.2, 3.5],
                "2.01 to 3": [7, 5.6, 4.2, 3.5, 2.8],
                "3.01 to 9.99": [7, 5.6, 4.2, 3.5, 2.8]
            },
            '8': {
                "0.5 or less": [7, 5.6, 4.2, 3.5, 2.8],
                "0.51 to 1": [7, 5.6, 4.2, 3.5, 2.8],
                "1.01 to 2": [4.2, 2.8, 2.52, 2.1, 1.68],
                "2.01 to 3": [4.2, 2.8, 2.1, 1.68, 1.4],
                "3.01 to 9.99": [4.2, 2.8, 2.1, 1.68, 1.4]
            },
            '10': {
                "0.5 or less": [9.8, 8.4, 6.3, 4.9, 4.2],
                "0.51 to 1": [9.8, 8.4, 6.3, 4.9, 4.2],
                "1.01 to 2": [7, 5.6, 4.9, 4.2, 3.5],
                "2.01 to 3": [7, 5.6, 4.2, 3.5, 2.8],
                "3.01 to 9.99": [7, 5.6, 4.2, 3.5, 2.8]
            }
        };
    }

    function getOtherMask1ozPrices() {
        return {
            '1': {
                "0.5 or less": [5.39, 3.85, 3.08, 2.695, 2.31],
                "0.51 to 1": [5.39, 3.85, 3.08, 2.695, 2.31],
                "1.01 to 2": [3.85, 1.694, 1.54, 1.232, 0.924],
                "2.01 to 3": [3.85, 1.694, 1.54, 0.979, 0.57],
                "3.01 to 9.99": [0, 1.694, 1.309, 0.939, 0.57]
            },
            '2': {
                "0.5 or less": [6.6, 5.94, 3.96, 3.136, 2.31],
                "0.51 to 1": [6.6, 5.28, 3.3, 2.806, 2.31],
                "1.01 to 2": [0, 3.036, 2.64, 2.146, 1.65],
                "2.01 to 3": [0, 0, 1.98, 1.782, 1.584],
                "3.01 to 9.99": [0, 0, 0, 1.65, 1.584]
            },
            '4': {
                "0.5 or less": [7], "0.51 to 1": [7], "1.01 to 2": [4.2], "2.01 to 3": [4.2], "3.01 to 9.99": [4.2]
            },
            '6': {
                "0.5 or less": [9.8], "0.51 to 1": [9.8], "1.01 to 2": [7], "2.01 to 3": [7], "3.01 to 9.99": [7]
            },
            '8': {
                "0.5 or less": [7], "0.51 to 1": [7], "1.01 to 2": [4.2], "2.01 to 3": [4.2], "3.01 to 9.99": [4.2]
            },
            '10': {
                "0.5 or less": [9.8], "0.51 to 1": [9.8], "1.01 to 2": [7], "2.01 to 3": [7], "3.01 to 9.99": [7]
            }
        };
    }

    function getGreenMask1ozOtherThicknessPrices() {
        return {
            '1': {
                "0.5 or less": [6.93, 4.62, 3.465, 2.888, 2.31],
                "0.51 to 1": [6.93, 4.62, 3.465, 2.888, 2.31],
                "1.01 to 2": [4.62, 2.31, 2.079, 1.617, 1.155],
                "2.01 to 3": [4.62, 2.31, 1.848, 1.617, 1.155],
                "3.01 to 9.99": [0, 2.31, 1.733, 1.271, 0.809]
            },
            '2': {
                "0.5 or less": [7.92, 6.93, 4.95, 3.96, 2.97],
                "0.51 to 1": [7.92, 5.94, 3.96, 3.466, 2.97],
                "1.01 to 2": [0, 3.96, 3.466, 2.723, 1.98],
                "2.01 to 3": [0, 0, 2.442, 2.212, 1.98],
                "3.01 to 9.99": [0, 0, 0, 2.278, 1.98]
            },
            '4': {
                "0.5 or less": [7], "0.51 to 1": [7], "1.01 to 2": [4.2], "2.01 to 3": [4.2], "3.01 to 9.99": [4.2]
            },
            '6': {
                "0.5 or less": [9.8], "0.51 to 1": [9.8], "1.01 to 2": [7], "2.01 to 3": [7], "3.01 to 9.99": [7]
            },
            '8': {
                "0.5 or less": [7], "0.51 to 1": [7], "1.01 to 2": [4.2], "2.01 to 3": [4.2], "3.01 to 9.99": [4.2]
            },
            '10': {
                "0.5 or less": [9.8], "0.51 to 1": [9.8], "1.01 to 2": [7], "2.01 to 3": [7], "3.01 to 9.99": [7]
            }
        };
    }

    function getOtherMask1ozOtherThicknessPrices() {
        return {
            '1': {
                "0.5 or less": [8.085, 5.775, 4.62, 4.043, 3.465],
                "0.51 to 1": [8.085, 5.775, 4.62, 4.043, 3.465],
                "1.01 to 2": [5.775, 2.541, 2.31, 1.848, 1.386],
                "2.01 to 3": [5.775, 2.541, 2.079, 1.467, 0.855],
                "3.01 to 9.99": [0, 2.541, 1.964, 1.41, 0.855]
            },
            '2': {
                "0.5 or less": [9.9, 8.91, 5.94, 4.712, 3.466],
                "0.51 to 1": [9.9, 7.92, 4.95, 4.208, 3.466],
                "1.01 to 2": [0, 4.554, 3.96, 3.234, 2.476],
                "2.01 to 3": [0, 0, 2.64, 2.508, 2.376],
                "3.01 to 9.99": [0, 0, 0, 2.508, 2.376]
            },
            '4': {
                "0.5 or less": [7], "0.51 to 1": [7], "1.01 to 2": [4.2], "2.01 to 3": [4.2], "3.01 to 9.99": [4.2]
            },
            '6': {
                "0.5 or less": [9.8], "0.51 to 1": [9.8], "1.01 to 2": [7], "2.01 to 3": [7], "3.01 to 9.99": [7]
            },
            '8': {
                "0.5 or less": [7], "0.51 to 1": [7], "1.01 to 2": [4.2], "2.01 to 3": [4.2], "3.01 to 9.99": [4.2]
            },
            '10': {
                "0.5 or less": [9.8], "0.51 to 1": [9.8], "1.01 to 2": [7], "2.01 to 3": [7], "3.01 to 9.99": [7]
            }
        };
    }

    function getGreenMask2ozPrices() {
        return {
            '1': {
                "0.5 or less": [9.24, 6.16, 4.62, 3.85, 3.08],
                "0.51 to 1": [9.24, 6.16, 4.62, 3.85, 3.08],
                "1.01 to 2": [6.16, 3.08, 2.772, 2.156, 1.54],
                "2.01 to 3": [6.16, 3.08, 2.464, 1.771, 1.078],
                "3.01 to 9.99": [0, 3.08, 2.31, 1.694, 1.078]
            },
            '2': {
                "0.5 or less": [10.56, 9.24, 6.6, 5.28, 3.96],
                "0.51 to 1": [10.56, 7.92, 5.28, 4.62, 3.96],
                "1.01 to 2": [0, 5.28, 4.62, 3.63, 2.64],
                "2.01 to 3": [0, 0, 3.3, 3.036, 2.64],
                "3.01 to 9.99": [0, 0, 0, 3.036, 2.64]
            },
            '4': {
                "0.5 or less": [7], "0.51 to 1": [7], "1.01 to 2": [4.2], "2.01 to 3": [4.2], "3.01 to 9.99": [4.2]
            },
            '6': {
                "0.5 or less": [9.8], "0.51 to 1": [9.8], "1.01 to 2": [7], "2.01 to 3": [7], "3.01 to 9.99": [7]
            },
            '8': {
                "0.5 or less": [7], "0.51 to 1": [7], "1.01 to 2": [4.2], "2.01 to 3": [4.2], "3.01 to 9.99": [4.2]
            },
            '10': {
                "0.5 or less": [9.8], "0.51 to 1": [9.8], "1.01 to 2": [7], "2.01 to 3": [7], "3.01 to 9.99": [7]
            }
        };
    }

    function getOtherMask2ozPrices() {
        return {
            '1': {
                "0.5 or less": [10.78, 7.7, 6.16, 5.39, 4.62],
                "0.51 to 1": [10.78, 7.7, 6.16, 5.39, 4.62],
                "1.01 to 2": [7.7, 3.388, 3.08, 2.464, 1.848],
                "2.01 to 3": [7.7, 3.388, 2.772, 1.956, 1.14],
                "3.01 to 9.99": [0, 3.388, 2.618, 1.879, 1.14]
            },
            '2': {
                "0.5 or less": [13.2, 11.88, 7.92, 6.27, 4.62],
                "0.51 to 1": [13.2, 10.56, 6.6, 5.61, 4.62],
                "1.01 to 2": [0, 6.072, 5.28, 4.29, 3.3],
                "2.01 to 3": [0, 0, 3.696, 3.432, 3.168],
                "3.01 to 9.99": [0, 0, 0, 3.3, 3.168]
            },
            '4': {
                "0.5 or less": [7], "0.51 to 1": [7], "1.01 to 2": [4.2], "2.01 to 3": [4.2], "3.01 to 9.99": [4.2]
            },
            '6': {
                "0.5 or less": [9.8], "0.51 to 1": [9.8], "1.01 to 2": [7], "2.01 to 3": [7], "3.01 to 9.99": [7]
            },
            '8': {
                "0.5 or less": [7], "0.51 to 1": [7], "1.01 to 2": [4.2], "2.01 to 3": [4.2], "3.01 to 9.99": [4.2]
            },
            '10': {
                "0.5 or less": [9.8], "0.51 to 1": [9.8], "1.01 to 2": [7], "2.01 to 3": [7], "3.01 to 9.99": [7]
            }
        };
    }

    function getGreenMask2ozOtherThicknessPrices() {
        return {
            '1': {
                "0.5 or less": [13.86, 9.24, 6.93, 5.775, 4.62],
                "0.51 to 1": [13.86, 9.24, 6.93, 5.775, 4.62],
                "1.01 to 2": [9.24, 4.62, 4.158, 3.234, 2.31],
                "2.01 to 3": [9.24, 4.62, 3.696, 2.657, 1.617],
                "3.01 to 9.99": [0, 4.62, 3.465, 2.541, 1.617]
            },
            '2': {
                "0.5 or less": [15.84, 13.86, 9.9, 7.92, 5.94],
                "0.51 to 1": [15.84, 11.88, 7.92, 6.93, 5.94],
                "1.01 to 2": [0, 7.92, 6.93, 5.446, 3.96],
                "2.01 to 3": [0, 0, 4.752, 4.554, 3.96],
                "3.01 to 9.99": [0, 0, 0, 4.554, 3.96]
            },
            '4': {
                "0.5 or less": [7], "0.51 to 1": [7], "1.01 to 2": [4.2], "2.01 to 3": [4.2], "3.01 to 9.99": [4.2]
            },
            '6': {
                "0.5 or less": [9.8], "0.51 to 1": [9.8], "1.01 to 2": [7], "2.01 to 3": [7], "3.01 to 9.99": [7]
            },
            '8': {
                "0.5 or less": [7], "0.51 to 1": [7], "1.01 to 2": [4.2], "2.01 to 3": [4.2], "3.01 to 9.99": [4.2]
            },
            '10': {
                "0.5 or less": [9.8], "0.51 to 1": [9.8], "1.01 to 2": [7], "2.01 to 3": [7], "3.01 to 9.99": [7]
            }
        };
    }

    function getOtherMask2ozOtherThicknessPrices() {
        return {
            '1': {
                "0.5 or less": [16.17, 11.55, 9.24, 8.085, 6.93],
                "0.51 to 1": [16.17, 11.55, 9.24, 8.085, 6.93],
                "1.01 to 2": [11.55, 5.082, 4.62, 3.696, 2.772],
                "2.01 to 3": [11.55, 5.082, 4.158, 2.941, 1.709],
                "3.01 to 9.99": [0, 5.082, 3.927, 2.818, 1.709]
            },
            '2': {
                "0.5 or less": [19.8, 17.82, 11.88, 9.406, 6.93],
                "0.51 to 1": [19.8, 15.84, 9.9, 8.416, 6.93],
                "1.01 to 2": [0, 9.108, 7.92, 6.436, 4.95],
                "2.01 to 3": [0, 0, 5.148, 4.95, 4.752],
                "3.01 to 9.99": [0, 0, 0, 4.95, 4.752]
            },
            '4': {
                "0.5 or less": [7], "0.51 to 1": [7], "1.01 to 2": [4.2], "2.01 to 3": [4.2], "3.01 to 9.99": [4.2]
            },
            '6': {
                "0.5 or less": [9.8], "0.51 to 1": [9.8], "1.01 to 2": [7], "2.01 to 3": [7], "3.01 to 9.99": [7]
            },
            '8': {
                "0.5 or less": [7], "0.51 to 1": [7], "1.01 to 2": [4.2], "2.01 to 3": [4.2], "3.01 to 9.99": [4.2]
            },
            '10': {
                "0.5 or less": [9.8], "0.51 to 1": [9.8], "1.01 to 2": [7], "2.01 to 3": [7], "3.01 to 9.99": [7]
            }
        };
    }

    document.getElementById('layers').addEventListener('change', calculatePrices);
    document.getElementById('length').addEventListener('input', calculatePrices);
    document.getElementById('width').addEventListener('input', calculatePrices);
    document.getElementById('quantity').addEventListener('input', calculatePrices);
    document.getElementById('quantity').addEventListener('change', function() {
        normalizeQuantity(true);
        calculatePrices();
    });
    document.getElementById('solderMask').addEventListener('change', calculatePrices);
    document.getElementById('copperWeight').addEventListener('change', calculatePrices);
    document.getElementById('thickness').addEventListener('input', calculatePrices);

    calculatePrices();

    function validation() {
        var boardName = document.getElementById('boardName').value;
        var userMobile = document.getElementById('userMobile').value;
        var userEmail = document.getElementById('userEmail').value;
        var quantity = normalizeQuantity(true);       
        var length = document.getElementById('length').value;
        var width = document.getElementById('width').value;
        if (boardName == "") {
            showCustomAlert('Please enter a Board Name');
            document.getElementById('boardName').focus();
            return false;
        } else if (userMobile == "") {
            showCustomAlert('Please enter Mobile Number');
            document.getElementById('userMobile').focus();
            return false;
        } else if (!validatePhone(userMobile)) {
            showCustomAlert('Please enter a valid 10-digit mobile number.');           
            document.getElementById('userMobile').focus();
            return false;
        } else if (userEmail == "") {
            showCustomAlert('Please enter Email Address');
            document.getElementById('userEmail').focus();
            return false;
        } else if (!validateEmail(userEmail)) {
            showCustomAlert('Please enter a valid email address.');
            document.getElementById('userEmail').focus();
            return false;
        } else if (quantity < MIN_ORDER_QUANTITY) {
            showCustomAlert('Minimum order quantity is ' + MIN_ORDER_QUANTITY);
            document.getElementById('quantity').focus();
            return false;
        } else if (length == "" || length < 1) {
            showCustomAlert('Please enter Board Length');
            document.getElementById('length').focus();
            return false;
        } else if (width == "" || width < 1) {
            showCustomAlert('Please enter Board Width');
            document.getElementById('width').focus();
            return false;
        }
        return true;
    }

    function validateEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }

    function validatePhone(phone) {
        const re = /^\d{10}$/;
        return re.test(phone);
    }

    function handleLeadTimeSubmit(days) {
        const unitPrice = document.getElementById(`unitPrice${days}`).value;
        const orderValue = document.getElementById(`orderValue${days}`).value;

        const formData = new FormData();
        formData.append('leadTime', days);
        formData.append('unitPrice', unitPrice);
        formData.append('orderValue', orderValue);
        formData.append('boardName', document.getElementById('boardName').value);
        formData.append('userMobile', document.getElementById('userMobile').value);
        formData.append('userEmail', document.getElementById('userEmail').value);
        formData.append('quantity', normalizeQuantity(true));
        formData.append('layers', document.getElementById('layers').value);
        formData.append('material', document.getElementById('material').value);
        formData.append('thickness', document.getElementById('thickness').value);
        formData.append('solderMask', document.getElementById('solderMask').value);
        formData.append('surfaceFinish', document.getElementById('surfaceFinish').value);
        formData.append('copperWeight', document.getElementById('copperWeight').value);
        formData.append('length', document.getElementById('length').value);
        formData.append('width', document.getElementById('width').value);
        formData.append('remarks', document.getElementById('remarks').value);
        formData.append('customerName', document.getElementById('customerName').value);
        formData.append('gstNumber', document.getElementById('gstNumber').value);
        formData.append('billingAddress', document.getElementById('billingAddress').value);
        formData.append('shippingAddress', document.getElementById('shippingAddress').value);
        
        const files = document.getElementById('fileUpload').files;
        for (let i = 0; i < files.length; i++) {
            formData.append('files', files[i]);
        }

        fetch('sendorder.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(result => {
            document.getElementById('orderForm').reset();
            showCustomAlert(result || 'Order submitted successfully!');
        })
        .catch(error => {
            showCustomAlert('Submission error: ' + error.message);
        });
    }   

    const leadTimeSubmits = [
        { id: 'submit1', days: 1 },
        { id: 'submit3', days: 3 },
        { id: 'submit5', days: 5 },
        { id: 'submit7', days: 7 },
        { id: 'submit10', days: 10 },
        { id: 'submit20', days: 20 }
    ];

    leadTimeSubmits.forEach(item => {
        const btn = document.getElementById(item.id);
        if (btn) {
            btn.addEventListener('click', function(event) {
                event.preventDefault();
                if (validation()) {
                    calculatePrices();
                    handleLeadTimeSubmit(item.days);
                }
            });
        }
    });

    function updateLeadTimeVisibility() {
        const totalArea = parseFloat(document.getElementById("totalAreaInSqM").value) || 0;
        const layers = parseInt(document.getElementById("layers").value);

        const leadTimes = ["submit1", "submit3", "submit5", "submit7", "submit10", "submit20"];

        leadTimes.forEach(id => {
            const btn = document.getElementById(id);
            const row = btn ? btn.closest(".lead-time-row") || btn.closest(".form-group") : null;
            if (row) row.style.display = "grid";
        });
        
        if (layers >= 4 && layers <= 10) {
            hideLeadTimes(["submit1","submit3","submit5","submit7","submit10"]);
            removeContactMessage();
            return;
        }
        
        if (layers === 1 || layers === 2) {
            hideLeadTimes(["submit20"]);
        }
        
        if (layers === 2 && totalArea > 7) {
            hideAllLeadTimes(leadTimes);
            displayContactMessage();
        } else if (layers === 1 && totalArea > 10) {
            hideAllLeadTimes(leadTimes);
            displayContactMessage();
        } else {
            hideLeadTimesBasedOnArea(totalArea, layers);
            removeContactMessage();
        }
    }

    function hideLeadTimesBasedOnArea(totalArea, layers) {
        if (layers === 2) {
            if (totalArea > 2) {
                hideLeadTimes(["submit1", "submit3", "submit5"]);
            } else if (totalArea > 1.5) {
                hideLeadTimes(["submit1", "submit3"]);
            } else if (totalArea > 1) {
                hideLeadTimes(["submit1"]);
            }
        } else if (layers === 1) {
            if (totalArea > 5) {
                hideLeadTimes(["submit1", "submit3", "submit5"]);
            } else if (totalArea > 3) {
                hideLeadTimes(["submit1", "submit3"]);
            } else if (totalArea > 2) {
                hideLeadTimes(["submit1"]);
            }
        }
    }

    function hideLeadTimes(ids) {
        ids.forEach(id => {
            const btn = document.getElementById(id);
            const row = btn ? btn.closest(".lead-time-row") || btn.closest(".form-group") : null;
            if (row) row.style.display = "none";
        });
    }

    function hideAllLeadTimes(ids) {
        hideLeadTimes(ids);
    }

    function displayContactMessage() {
        let messageDiv = document.getElementById("contactMessage");
        if (!messageDiv) {
            messageDiv = document.createElement("div");
            messageDiv.id = "contactMessage";
            messageDiv.style.padding = "16px 20px";
            messageDiv.style.margin = "20px 0";
            messageDiv.style.backgroundColor = "#fef2f2";
            messageDiv.style.color = "#991b1b";
            messageDiv.style.border = "1px solid #fecaca";
            messageDiv.style.borderRadius = "12px";
            messageDiv.style.textAlign = "center";
            messageDiv.style.fontWeight = "600";
            messageDiv.style.fontSize = "14px";
            messageDiv.innerHTML = `<strong>Note:</strong> For larger orders, please contact us at <a href="tel:9898842942" style="color:#dc2626; font-weight:800;">9898842942</a> or <a href="tel:8160282840" style="color:#dc2626; font-weight:800;">8160282840</a>.`;
            const container = document.querySelector(".pcb-wrapper") || document.body;
            container.prepend(messageDiv);
        }
    }

    function removeContactMessage() {
        const messageDiv = document.getElementById("contactMessage");
        if (messageDiv) {
            messageDiv.remove();
        }
    }

    document.addEventListener('DOMContentLoaded', updateLeadTimeVisibility);
    const totalAreaInput = document.getElementById('totalAreaInSqM');
    const layersInput = document.getElementById('layers');

    if (totalAreaInput && layersInput) {
        totalAreaInput.addEventListener('input', updateLeadTimeVisibility);
        layersInput.addEventListener('change', updateLeadTimeVisibility);
    }
</script>
