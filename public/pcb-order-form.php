<div class="col-lg-12 col-md-12 col-sm-12">
    <div class="title textCenter">
        <h2 class="animated" data-animated-in="animate__fadeInUp">PCB <span>Order Form</span></h2>
    </div>
</div>
<div class="col-lg-12 col-md-12 col-sm-12">
    <div class="pcb-order-block">
        <div class="row">
            <div class="col-lg-5 col-md-5 col-sm-12">
                <form id="orderForm" action="#" method="POST" enctype="multipart/form-data">

                    <div class="form-group">
                        <label class="control-label" for="boardName"><b>Board Name:</b> <i class="fas fa-question-circle" data-toggle="tooltip" title="Enter a descriptive name for your PCB design."></i></label>
                        <input class="form-control" type="text" id="boardName" name="boardName" placeholder="Enter Board Name">
                    </div>

                    <div class="row">
                        <div class="col-lg-6 col-md-6">
                            <div class="form-group">
                                <label class="control-label" for="userMobile"><b>Mobile:</b> <i class="fas fa-question-circle" data-toggle="tooltip" title="Enter your 10-digit mobile number without any spaces or special characters."></i></label>
                                <input class="form-control" type="tel" id="userMobile" name="userMobile" placeholder="Enter Mobile Number" pattern="[0-9]{10}">
                            </div>
                        </div>
                        <div class="col-lg-6 col-md-6">
                            <div class="form-group">
                                <label class="control-label" for="userEmail">User Email: <i class="fas fa-question-circle" data-toggle="tooltip" title="Provide a valid email address for communication."></i></label>
                                <input class="form-control" type="email" id="userEmail" name="userEmail" placeholder="Enter Email Address" required="">
                            </div>
                        </div>
                    </div>

                    <!-- Board Size -->
                    <div class="form-group">
                        <label class="control-label" for="length"><b>Board Size (mm):</b> <i class="fas fa-question-circle" data-toggle="tooltip" title="Specify the length and width of the PCB in millimeters."></i></label>
                        <div class="row">
                            <div class="col-lg-6 col-md-6 col-sm-6 col-xs-6">
                                <input class="form-control" type="number" id="length" name="length" placeholder="Length" min="20" required="">
                            </div>
                            <div class="col-lg-6 col-md-6 col-sm-6 col-xs-6">
                                <input class="form-control" type="number" id="width" name="width" placeholder="Width" min="20" required="">
                            </div>
                        </div>
                    </div>

                    <!-- Quantity -->
                    <div class="form-group">
                        <div class="row">
                            <div class="col-lg-6 col-md-6">
                                <label class="control-label" for="quantity"><b>Quantity:</b> <i class="fas fa-question-circle" data-toggle="tooltip" title="Enter the number of PCB units you want to order."></i></label>
                            </div>
                            <div class="col-lg-6 col-md-6">
                                <input class="form-control" type="number" id="quantity" name="quantity" placeholder="Quantity" min="3" value="3" required="" />
                            </div>
                        </div>
                    </div>

                    
                    <div class="row">
                        <div class="col-lg-6 col-md-6">
                            <div class="form-group">
                                <label class="control-label" for="layers"><b>Layers:</b> <i class="fas fa-question-circle" data-toggle="tooltip" title="The number of conductive copper layers in your PCB design."></i></label>
                                <select class="form-control" id="layers" name="layers">
                                    <option value="1">1 Layer</option>
                                    <option value="2">2 Layers</option>
									<option value="4">4 Layers</option>
									<option value="6">6 Layers</option>
									<option value="8">8 Layers</option>
									<option value="10">10 Layers</option>
								</select>
                            </div>
                        </div>
                        <div class="col-lg-6 col-md-6">
                            <div class="form-group">
                                <label class="control-label" for="material"><b>Material:</b> <i class="fas fa-question-circle" data-toggle="tooltip" title="The base material used for the PCB, typically FR-4, a fiberglass epoxy laminate."></i></label>
                                <select class="form-control" id="material" name="material">
                                    <option value="FR4">FR-4</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    

                    <!-- Thickness, Solder Mask in same row -->
                    
                    <div class="row">
                        <div class="col-lg-6 col-md-6">
                            <div class="form-group">
                                <label class="control-label" for="thickness"><b>Thickness (mm):</b> <i class="fas fa-question-circle" data-toggle="tooltip" title="The overall thickness of the PCB, measured in millimeters."></i></label>
                                <select class="form-control" id="thickness" name="thickness">
                                    <option value="1.6">1.6 mm</option>
                                    <option value="0.6">0.6 mm</option>
                                    <option value="0.8">0.8 mm</option>
                                    <option value="1.0">1.0 mm</option>
                                    <option value="1.2">1.2 mm</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-lg-6 col-md-6">
                            <div class="form-group">
                                <label class="control-label" for="solderMask"><b>Solder Mask:</b> <i class="fas fa-question-circle" data-toggle="tooltip" title="A protective layer applied over the copper traces to prevent oxidation and short circuits."></i></label>
                                <select class="form-control" id="solderMask" name="solderMask">
                                    <option value="Green">Green</option>
                                    <option value="Red">Red</option>
                                    <option value="Blue">Blue</option>
                                    <option value="Black">Black</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    

                    <!-- Surface Finish and Copper Weight in same row -->
                    
                    <div class="row">
                        <div class="col-lg-6 col-md-6">
                            <div class="form-group">
                                <label class="control-label" for="Surface Finish"><b>Surface Finish:</b> <i class="fas fa-question-circle" data-toggle="tooltip" title="The coating applied to the PCB's exposed copper to protect it and improve solderability."></i></label>
                                <select class="form-control" id="surfaceFinish" name="surfaceFinish">
                                    <option value="HASL(Leaded)">HASL(Leaded)</option>
                                    <option value="Roller Tin">Roller Tin</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-lg-6 col-md-6">
                            <div class="form-group">
                                <label class="control-label" for="copperWeight"><b>Copper Weight:</b> <i class="fas fa-question-circle" data-toggle="tooltip" title="The thickness of copper used in the PCB, measured in ounces per square foot."></i></label>
                                <select class="form-control" id="copperWeight" name="copperWeight">
                                    <option value="1oz">1 oz</option>
                                    <option value="2oz">2 oz</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    

                    
                    <div class="row">
                        <div class="col-lg-6 col-md-6">
                            <div class="form-group">
                                <label class="control-label" for="remarks">Remarks:</label>
                                <input class="form-control" type="text" id="remarks" name="remarks" placeholder="Enter Remarks">
                            </div>
                        </div>
                        <div class="col-lg-6 col-md-6">
                            <div class="form-group">
                                <label class="control-label" for="fileUpload">Upload Files:</label>
                                <input class="form-control" type="file" id="fileUpload" name="files" multiple="" accept=".gerber,.dxf,.zip">
                            </div>
                        </div>
                    </div>
                    
                </form>
            </div>
            <div class="col-lg-7 col-md-7 col-sm-12">
                
                

                <!-- New Fields Below Lead Time Section -->
                <div class="leadTimeDiv">
                    <h3 class="form-title">Lead Time</h3>
                    <div class="leadTimeData">
                        <div class="form-group">
                            <div class="row">
                                <div class="col-lg-3 col-md-3">
                                    <label class="control-label" for="">Lead Time</label>
                                </div>
                                <div class="col-lg-3 col-md-3">
                                    <label class="control-label" for="">Unit Price</label>                                 
                                </div>                                
                                <div class="col-lg-3 col-md-3">
                                     <label class="control-label" for="">Order Value</label>  
                                </div>
                                <div class="col-lg-3 col-md-3">
                                    
                                </div>
                            </div>   
                        </div>
                        <div class="form-group">   
                            <div class="row">                             
                                <div class="col-lg-3 col-md-3">
                                    <label class="control-label" for="">1 Day </label>
                                </div>                       
                                <div class="col-lg-3 col-md-3">
                                     <input class="form-control input-lg" type="text" id="unitPrice1" placeholder="Unit Price" readonly="">                           
                                </div>
                                <div class="col-lg-3 col-md-3">
                                 <input class="form-control input-lg" type="text" id="orderValue1" placeholder="Order Value" readonly="">
                                </div>
                                <div class="col-lg-3 col-md-3">
                                    <button class="bigButton btnClr1 disBlock" type="button" id="submit1">Submit</button>                               
                                </div>   
                            </div>
                        </div>
                        <div class="form-group">
                            <div class="row">                             
                                <div class="col-lg-3 col-md-3">
                                    <label class="control-label" for="">3 Day </label>
                                </div>                       
                                <div class="col-lg-3 col-md-3">
                                     <input class="form-control input-lg" type="text" id="unitPrice3" placeholder="Unit Price" readonly="">                           
                                </div>
                                <div class="col-lg-3 col-md-3">
                                 <input class="form-control input-lg" type="text" id="orderValue3" placeholder="Order Value" readonly="">
                                </div>
                                <div class="col-lg-3 col-md-3">
                                    <button class="bigButton btnClr1 disBlock" type="button" id="submit3">Submit</button>                               
                                </div>   
                            </div>
                        </div>
                        <div class="form-group">    
                            <div class="row">                             
                                <div class="col-lg-3 col-md-3">
                                    <label class="control-label" for="">5 Day </label>
                                </div>                       
                                <div class="col-lg-3 col-md-3">
                                     <input class="form-control input-lg" type="text" id="unitPrice5" placeholder="Unit Price" readonly="">                           
                                </div>
                                <div class="col-lg-3 col-md-3">
                                 <input class="form-control input-lg" type="text" id="orderValue5" placeholder="Order Value" readonly="">
                                </div>
                                <div class="col-lg-3 col-md-3">
                                    <button class="bigButton btnClr1 disBlock" type="button" id="submit5">Submit</button>                               
                                </div>   
                            </div>
                        </div>
                        <div class="form-group">    
                            <div class="row">                             
                                <div class="col-lg-3 col-md-3">
                                    <label class="control-label" for="">7 Day </label>
                                </div>                       
                                <div class="col-lg-3 col-md-3">
                                     <input class="form-control input-lg" type="text" id="unitPrice7" placeholder="Unit Price" readonly="">                           
                                </div>
                                <div class="col-lg-3 col-md-3">
                                 <input class="form-control input-lg" type="text" id="orderValue7" placeholder="Order Value" readonly="">
                                </div>
                                <div class="col-lg-3 col-md-3">
                                    <button class="bigButton btnClr1 disBlock" type="button" id="submit7">Submit</button>                               
                                </div>   
                            </div>
                        </div>
                        <div class="form-group">   
                            <div class="row">                             
                                <div class="col-lg-3 col-md-3">
                                    <label class="control-label" for="">10 Day </label>
                                </div>                       
                                <div class="col-lg-3 col-md-3">
                                     <input class="form-control input-lg" type="text" id="unitPrice10" placeholder="Unit Price" readonly="">                           
                                </div>
                                <div class="col-lg-3 col-md-3">
                                 <input class="form-control input-lg" type="text" id="orderValue10" placeholder="Order Value" readonly="">
                                </div>
                                <div class="col-lg-3 col-md-3">
                                    <button class="bigButton btnClr1 disBlock" type="button" id="submit10">Submit</button>                               
                                </div>   
                            </div>
                        </div>
						<div class="form-group">   
                            <div class="row">                             
                                <div class="col-lg-3 col-md-3">
                                    <label class="control-label" for="">20 Day </label>
                                </div>                       
                                <div class="col-lg-3 col-md-3">
                                     <input class="form-control input-lg" type="text" id="unitPrice20" placeholder="Unit Price" readonly="">                           
                                </div>
                                <div class="col-lg-3 col-md-3">
                                 <input class="form-control input-lg" type="text" id="orderValue20" placeholder="Order Value" readonly="">
                                </div>
                                <div class="col-lg-3 col-md-3">
                                    <button class="bigButton btnClr1 disBlock" type="button" id="submit20">Submit</button>                               
                                </div>   
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                            <div class="row">
                                <div class="col-lg-6 col-md-6">
                                    <label class="control-label" for="totalAreaInSqM">Total Square Meter:</label>
                                </div>
                                <div class="col-lg-6 col-md-6">
                                    <input class="form-control" type="text" id="totalAreaInSqM" name="totalSqMeter" placeholder="0.00" readonly>
                </div>
                
                <div class="row">
                    <div class="col-lg-6 col-md-6">
                        <div class="form-group">
                            <label class="control-label" for="customerName">Customer Name:</label>
                            <input class="form-control" type="text" id="customerName" name="customerName" placeholder="Enter Customer Name">
                        </div>
                    </div>
                    <div class="col-lg-6 col-md-6">
                        <div class="form-group">
                            <label class="control-label" for="gstNumber">GST Number:</label>
                            <input class="form-control" type="text" id="gstNumber" name="gstNumber" placeholder="Enter GST Number">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-lg-6 col-md-6">
                        <div class="form-group">
                            <label class="control-label" for="billingAddress">Billing Address:</label>
                            <textarea class="form-control" rows="3" id="billingAddress" name="billingAddress" placeholder="Enter Billing Address"></textarea>
                        </div>
                    </div>    
                    <div class="col-lg-6 col-md-6">    
                        <div class="form-group">
                            <label class="control-label" for="shippingAddress">Shipping Address:</label>
                            <textarea class="form-control" id="shippingAddress" name="shippingAddress" rows="3" placeholder="Enter Shipping Address"></textarea>
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

    </div>

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

        // Trigger recalculation of price based on updated dimensions
        calculatePrices(length, width);
    }

    function calculatePrices(length, width) {
        // Ensure the minimum length and width are applied for pricing calculation
        length = Math.max(length, 20);
        width = Math.max(width, 20);

        const area = length * width;
        const basePricePerSqMm = 0.05; // Example base price per square mm

        const totalPrice = area * basePricePerSqMm;

        document.getElementById('price').innerText = `Price: $${totalPrice.toFixed(2)}`;
    }

    function showCustomAlert(message) {
        const alertBox = document.createElement('div');
        alertBox.style.position = 'fixed';
        alertBox.style.top = '20px';
        alertBox.style.right = '20px';
        alertBox.style.padding = '20px';
        alertBox.style.background = 'linear-gradient(135deg, #ff6f61, #ff4757)';
        alertBox.style.color = '#fff';
        alertBox.style.borderRadius = '12px';
        alertBox.style.boxShadow = '0 8px 20px rgba(0, 0, 0, 0.2)';
        alertBox.style.fontSize = '16px';
        alertBox.style.fontWeight = 'bold';
        alertBox.style.display = 'flex';
        alertBox.style.justifyContent = 'space-between';
        alertBox.style.alignItems = 'center';
        alertBox.style.maxWidth = '300px';
        alertBox.style.zIndex = '1000';

        const alertMessage = document.createElement('span');
        alertMessage.innerText = message;

        const closeBtn = document.createElement('span');
        closeBtn.innerText = '✖';
        closeBtn.style.marginLeft = '10px';
        closeBtn.style.cursor = 'pointer';
        closeBtn.style.fontWeight = 'bold';
        closeBtn.style.fontSize = '18px';
        closeBtn.style.transition = 'color 0.3s ease';

        closeBtn.addEventListener('click', () => {
            alertBox.remove();
        });

        closeBtn.addEventListener('mouseover', () => {
            closeBtn.style.color = '#ffd700';
        });

        closeBtn.addEventListener('mouseout', () => {
            closeBtn.style.color = '#fff';
        });

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

        // Calculate area per board in sq m
        const areaPerBoard = (length * width) / AREA_CONVERSION_FACTOR;
        const totalAreaInSqM = areaPerBoard * quantity;
        
        document.getElementById('totalAreaInSqM').value = totalAreaInSqM.toFixed(2);
        
        updateLeadTimeVisibility(totalAreaInSqM, layers);

        // Convert total area to sq cm
        const areaInSqCm = totalAreaInSqM * CM_CONVERSION_FACTOR;

        // Define fixed costs for each layer type
        const fixedCosts = {
            '1': { 1: 3100, 3: 2100, 5: 1600, 7: 1500, 10: 1400 },
            '2': { 1: 8100, 3: 4100, 5: 2600, 7: 2200, 10: 1900 },
            '4': { 20: 6000 },
            '6': { 20: 7000 },
            '8': { 20: 8000 },
            '10': { 20: 9000 }      					  						  
        };

        // Define price tiers based on mask, copper weight, and thickness criteria
        let priceTiers = getPriceTiers(solderMask, copperWeight, thickness);

        if (!priceTiers) {
            resetPrices();
            alert("No pricing available for the selected criteria.");
            return;
        }

        // Determine the applicable price bracket
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
            alert("Area exceeds pricing range. Please contact us for a custom quote.");
            return;
        }

        // Update lead time prices based on the applicable prices and fixed costs
        updateLeadTimePrices(1, areaInSqCm, applicablePrices[0], quantity, fixedCosts[layers][1]);
        updateLeadTimePrices(3, areaInSqCm, applicablePrices[1], quantity, fixedCosts[layers][3]);
        updateLeadTimePrices(5, areaInSqCm, applicablePrices[2], quantity, fixedCosts[layers][5]);
        updateLeadTimePrices(7, areaInSqCm, applicablePrices[3], quantity, fixedCosts[layers][7]);
        updateLeadTimePrices(10, areaInSqCm, applicablePrices[4], quantity, fixedCosts[layers][10]);
        updateLeadTimePrices(20, areaInSqCm, applicablePrices[0], quantity, fixedCosts[layers][20]);   
																						  

		// existing calculations...

		// Update lead time visibility dynamically
		// Update lead time visibility dynamically (pass the real total area)
		updateLeadTimeVisibility(totalAreaInSqM, layers);
		hideLeadTimesBasedOnArea(totalAreaInSqM, layers);
	
    }
    
    
    

    function updateLeadTimePrices(days, areaInSqCm, costPerSqCm, quantity, fixedCost) {
        const variableCost = areaInSqCm * costPerSqCm;
        const totalCost = fixedCost + variableCost;
        const unitPrice = totalCost / quantity;

        document.getElementById(`unitPrice${days}`).value = unitPrice.toFixed(2);
        document.getElementById(`orderValue${days}`).value = totalCost.toFixed(2);
    }

    function resetPrices() {
        const days = [1, 3, 5, 7, 10, 20];
        days.forEach(day => {
            document.getElementById(`unitPrice${day}`).value = "";
            document.getElementById(`orderValue${day}`).value = "";
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

        return tiers[mask]?.[weight]?.[thickness] ?? tiers[mask]?.[weight]?.['other'] ?? tiers['Other']?.[weight]?.['other'] ?? null;


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
				"0.5 or less": [7],
				"0.51 to 1": [7],
				"1.01 to 2": [4.2],
				"2.01 to 3": [4.2],
				"3.01 to 9.99": [4.2]
			},
			'6': {
				"0.5 or less": [9.8],
				"0.51 to 1": [9.8],
				"1.01 to 2": [7],
				"2.01 to 3": [7],
				"3.01 to 9.99": [7]
			},
			'8': {
				"0.5 or less": [7],
				"0.51 to 1": [7],
				"1.01 to 2": [4.2],
				"2.01 to 3": [4.2],
				"3.01 to 9.99": [4.2]
			},
			'10': {
				"0.5 or less": [9.8],
				"0.51 to 1": [9.8],
				"1.01 to 2": [7],
				"2.01 to 3": [7],
				"3.01 to 9.99": [7]
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
				"0.5 or less": [7],
				"0.51 to 1": [7],
				"1.01 to 2": [4.2],
				"2.01 to 3": [4.2],
				"3.01 to 9.99": [4.2]
			},
			'6': {
				"0.5 or less": [9.8],
				"0.51 to 1": [9.8],
				"1.01 to 2": [7],
				"2.01 to 3": [7],
				"3.01 to 9.99": [7]
			},
			'8': {
				"0.5 or less": [7],
				"0.51 to 1": [7],
				"1.01 to 2": [4.2],
				"2.01 to 3": [4.2],
				"3.01 to 9.99": [4.2]
			},
			'10': {
				"0.5 or less": [9.8],
				"0.51 to 1": [9.8],
				"1.01 to 2": [7],
				"2.01 to 3": [7],
				"3.01 to 9.99": [7]
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
				"0.5 or less": [7],
				"0.51 to 1": [7],
				"1.01 to 2": [4.2],
				"2.01 to 3": [4.2],
				"3.01 to 9.99": [4.2]
			},
			'6': {
				"0.5 or less": [9.8],
				"0.51 to 1": [9.8],
				"1.01 to 2": [7],
				"2.01 to 3": [7],
				"3.01 to 9.99": [7]
			},
			'8': {
				"0.5 or less": [7],
				"0.51 to 1": [7],
				"1.01 to 2": [4.2],
				"2.01 to 3": [4.2],
				"3.01 to 9.99": [4.2]
			},
			'10': {
				"0.5 or less": [9.8],
				"0.51 to 1": [9.8],
				"1.01 to 2": [7],
				"2.01 to 3": [7],
				"3.01 to 9.99": [7]
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
				"0.5 or less": [7],
				"0.51 to 1": [7],
				"1.01 to 2": [4.2],
				"2.01 to 3": [4.2],
				"3.01 to 9.99": [4.2]
			},
			'6': {
				"0.5 or less": [9.8],
				"0.51 to 1": [9.8],
				"1.01 to 2": [7],
				"2.01 to 3": [7],
				"3.01 to 9.99": [7]
			},
			'8': {
				"0.5 or less": [7],
				"0.51 to 1": [7],
				"1.01 to 2": [4.2],
				"2.01 to 3": [4.2],
				"3.01 to 9.99": [4.2]
			},
			'10': {
				"0.5 or less": [9.8],
				"0.51 to 1": [9.8],
				"1.01 to 2": [7],
				"2.01 to 3": [7],
				"3.01 to 9.99": [7]
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
				"0.5 or less": [7],
				"0.51 to 1": [7],
				"1.01 to 2": [4.2],
				"2.01 to 3": [4.2],
				"3.01 to 9.99": [4.2]
			},
			'6': {
				"0.5 or less": [9.8],
				"0.51 to 1": [9.8],
				"1.01 to 2": [7],
				"2.01 to 3": [7],
				"3.01 to 9.99": [7]
			},
			'8': {
				"0.5 or less": [7],
				"0.51 to 1": [7],
				"1.01 to 2": [4.2],
				"2.01 to 3": [4.2],
				"3.01 to 9.99": [4.2]
			},
			'10': {
				"0.5 or less": [9.8],
				"0.51 to 1": [9.8],
				"1.01 to 2": [7],
				"2.01 to 3": [7],
				"3.01 to 9.99": [7]
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
				"0.5 or less": [7],
				"0.51 to 1": [7],
				"1.01 to 2": [4.2],
				"2.01 to 3": [4.2],
				"3.01 to 9.99": [4.2]
			},
			'6': {
				"0.5 or less": [9.8],
				"0.51 to 1": [9.8],
				"1.01 to 2": [7],
				"2.01 to 3": [7],
				"3.01 to 9.99": [7]
			},
			'8': {
				"0.5 or less": [7],
				"0.51 to 1": [7],
				"1.01 to 2": [4.2],
				"2.01 to 3": [4.2],
				"3.01 to 9.99": [4.2]
			},
			'10': {
				"0.5 or less": [9.8],
				"0.51 to 1": [9.8],
				"1.01 to 2": [7],
				"2.01 to 3": [7],
				"3.01 to 9.99": [7]
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
				"0.5 or less": [7],
				"0.51 to 1": [7],
				"1.01 to 2": [4.2],
				"2.01 to 3": [4.2],
				"3.01 to 9.99": [4.2]
			},
			'6': {
				"0.5 or less": [9.8],
				"0.51 to 1": [9.8],
				"1.01 to 2": [7],
				"2.01 to 3": [7],
				"3.01 to 9.99": [7]
			},
			'8': {
				"0.5 or less": [7],
				"0.51 to 1": [7],
				"1.01 to 2": [4.2],
				"2.01 to 3": [4.2],
				"3.01 to 9.99": [4.2]
			},
			'10': {
				"0.5 or less": [9.8],
				"0.51 to 1": [9.8],
				"1.01 to 2": [7],
				"2.01 to 3": [7],
				"3.01 to 9.99": [7]
			}
		};
	}

    // Add event listeners to trigger price calculation on input change
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

    function validation()
    {
        var boardName = document.getElementById('boardName').value;
        var userMobile = document.getElementById('userMobile').value;
        var userEmail = document.getElementById('userEmail').value;
        var quantity = normalizeQuantity(true);       
        var length = document.getElementById('length').value;
        var width = document.getElementById('width').value;
        if(boardName=="")
        {
            alert('PLease Enter Boardname');
            document.getElementById('boardName').focus();
            return false;
        }
        else if(userMobile=="")
        {
            alert('PLease Enter Mobile No');
            document.getElementById('userMobile').focus();
            return false;
        }
        else if(!validatePhone(userMobile))
        {
            alert('Please enter a valid mobile number.');           
            document.getElementById('userMobile').focus();
            return false;
        }        
        else if(userEmail=="")
        {
            alert('PLease Enter Email');
            document.getElementById('userEmail').focus();
            return false;
        }
        else if (!validateEmail(userEmail)) 
        {
            alert('Please enter a valid email address.');
            document.getElementById('userEmail').focus();
            return false;
        }
        else if(quantity < MIN_ORDER_QUANTITY)
		{
			alert('Minimum order quantity is ' + MIN_ORDER_QUANTITY);
			document.getElementById('quantity').focus();
			return false;
		}
        else if(length=="" && length < 1)
        {
            alert('PLease Enter Length');
            document.getElementById('length').focus();
            return false;
        }
        else if(width=="" && width < 1)
        {
            alert('PLease Enter Width');
            document.getElementById('width').focus();
            return false;
        }
        return true;
    }

    // Event listeners for input changes
    document.getElementById('layers').addEventListener('change', calculatePrices);
    document.getElementById('length').addEventListener('input', calculatePrices);
    document.getElementById('width').addEventListener('input', calculatePrices);
    document.getElementById('quantity').addEventListener('input', calculatePrices);
    document.getElementById('quantity').addEventListener('change', function() {
        normalizeQuantity(true);
        calculatePrices();
    });

    
    function validateEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }

    function validatePhone(phone) {
        const re = /^\d{10}$/; // Example for 10 digit phone number
        return re.test(phone);
    }
    async function downloadPDF(days) {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF();

        let serialNumber;
        try {
            const response = await fetch('http://localhost:3000/get-serial-number'); // Ensure full URL
            if (!response.ok) {
                throw new Error('Failed to fetch serial number: ' + response.statusText);
            }
            const data = await response.json();
            serialNumber = data.serialNumber; // Assume your server returns { serialNumber: <number> }
        } catch (error) {
            console.error('Error fetching serial number:', error);
            alert('There was an error fetching the serial number. Please try again later.');
            return; // Exit the function if there's an error
        }

        // Unified Header Section with Gradient
        const headerHeight = 50;
        doc.setFillColor(0, 102, 204); // Base blue color
        doc.rect(0, 0, doc.internal.pageSize.width, headerHeight, 'F'); // Unified header background

        // Company Name and Order Details in Unified Style
        doc.setFontSize(28);
        doc.setFont("helvetica", "bold");
        doc.setTextColor(255, 255, 255); // White text
        doc.text("Megabytes Circuit Systems", 10, 20); // Positioned at the top-left

        doc.setFontSize(18);
        doc.text("Order Details", 10, 40); // Positioned below the company title

        // Generate Quote Number
        const currentYear = new Date().getFullYear(); // Get the current year
        const yearRange = `${currentYear.toString().slice(-2)}-${(currentYear + 1).toString().slice(-2)}`; // Format as 24-25
        const quoteNumber = `WO#${serialNumber}/${yearRange}`; // Format the quote number

        // Date and Time
        const date = new Date();
        const formattedDate = date.toLocaleString(); // Format date and time
        doc.setFontSize(12);
        doc.setTextColor(230, 230, 230); // Light white text
        doc.text(`Date: ${formattedDate}`, doc.internal.pageSize.width - 70, 40); // Positioned at the top-right
        doc.text(`Quote Number: ${quoteNumber}`, doc.internal.pageSize.width - 70, 45); // Add Quote Number

        // Body Content: Details Section with Alternating Colors
        const details = [
            { label: "Board Name", value: document.getElementById('boardName').value },
            { label: "Mobile", value: document.getElementById('userMobile').value },
            { label: "Email", value: document.getElementById('userEmail').value },
            { label: "Length", value: document.getElementById('length').value },
            { label: "Width", value: document.getElementById('width').value },
            { label: "Quantity", value: normalizeQuantity(true) },
            { label: "Layers", value: document.getElementById('layers').value },
            { label: "Material", value: document.getElementById('material').value },
            { label: "Thickness", value: document.getElementById('thickness').value },
            { label: "Solder Mask", value: document.getElementById('solderMask').value },
            { label: "Surface Finish", value: document.getElementById('surfaceFinish').value },
            { label: "Copper Weight", value: document.getElementById('copperWeight').value },
            { label: "Remarks", value: document.getElementById('remarks').value },
            { label: "Unit Price", value: document.getElementById(`unitPrice`).value },
            { label: "Order Value", value: document.getElementById(`orderValue`).value },
        ];

        let startY = 60; // Starting Y position for the details
        let rowColors = [
            [240, 248, 255], // Light blue (even rows)
            [255, 255, 255]  // White (odd rows)
        ];

        doc.setFont("helvetica", "normal");
        doc.setFontSize(14);

        details.forEach((detail, index) => {
            // Set alternating row background colors
            doc.setFillColor(...rowColors[index % 2]);
            doc.rect(10, startY - 5, doc.internal.pageSize.width - 20, 10, "F");

            // Add labels
            doc.setTextColor(0, 51, 153); // Deep blue
            doc.text(`${detail.label}:`, 12, startY);

            // Add values
            doc.setTextColor(0, 0, 0); // Black
            doc.text(detail.value || "N/A", 80, startY);

            startY += 10; // Increment Y for the next row
        });

        // Footer Section - Branding and Page Numbers
        const footerY = doc.internal.pageSize.height - 10;
        doc.setFontSize(10);
        doc.setTextColor(150, 150, 150); // Grey text
        doc.text("Generated by Megabytes Circuit Systems", 10, footerY); // Footer branding
        doc.text(`Page 1`, doc.internal.pageSize.width - 30, footerY); // Page number

        // Save the PDF
        doc.save('PCB_Order_Details.pdf');
    }


    function handleLeadTimeSubmit(days) {
        const unitPrice = document.getElementById(`unitPrice${days}`).value;
        const orderValue = document.getElementById(`orderValue${days}`).value;
        //const days = document.getElementById(`leadTime`).value;

        // Log values to verify
        console.log("Board Name:", document.getElementById('boardName').value);
        console.log("Mobile:", document.getElementById('userMobile').value);
        console.log("Email:", document.getElementById('userEmail').value);
        console.log("Length:", document.getElementById('length').value);
        console.log("Width:", document.getElementById('width').value);
        console.log("Unit Price:", unitPrice);
        console.log("Order Value:", orderValue);

       
        // Create Form Data to send to server
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
        
        // Append uploaded files
        const files = document.getElementById('fileUpload').files; // Get the files from the input
        for (let i = 0; i < files.length; i++) {
            formData.append('files', files[i]); // Append each file to the FormData
        }

        // Send the FormData to the server
        fetch('sendorder.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(result => {
			document.getElementById('orderForm').reset();
            alert(result);
			
            // Download the PDF before resetting the form
           /* downloadPDF(days).then(() => {
                document.getElementById('orderForm').reset();
                document.getElementById('progressBar').style.width = '0%'; // Reset progress bar
            });*/
        })
        .catch(error => {
            alert('There was a problem with the submission: ' + error.message);
        });
    }   
    // Event listeners for lead time submit buttons
    document.getElementById('submit1').addEventListener('click', function(event) {
        event.preventDefault();
        if(validation())
        {
            calculatePrices(); // Ensure prices are calculated before submitting
            handleLeadTimeSubmit(1);
        }
    });
    document.getElementById('submit10').addEventListener('click', function(event) {
        event.preventDefault();
        if(validation())
        {
            calculatePrices(); // Ensure prices are calculated before submitting
            handleLeadTimeSubmit(10);
        }
    });
	document.getElementById('submit20').addEventListener('click', function(event) {
        event.preventDefault();
        if(validation())
        {
            calculatePrices(); // Ensure prices are calculated before submitting
            handleLeadTimeSubmit(20);
        }
    });
    document.getElementById('submit3').addEventListener('click', function(event) {
        event.preventDefault();
        if(validation())
        {
            calculatePrices(); // Ensure prices are calculated before submitting
            handleLeadTimeSubmit(3);
        }
    });
    document.getElementById('submit5').addEventListener('click', function(event) {
        event.preventDefault();
        if(validation())
        {
            calculatePrices(); // Ensure prices are calculated before submitting
            handleLeadTimeSubmit(5);
        }
    });
    document.getElementById('submit7').addEventListener('click', function(event) {
        event.preventDefault();
        if(validation())
        {
            calculatePrices(); // Ensure prices are calculated before submitting
            handleLeadTimeSubmit(7);
        }
    });

   
</script>

<script>
function updateLeadTimeVisibility() {
    const totalArea = parseFloat(document.getElementById("totalAreaInSqM").value) || 0;
    const layers = parseInt(document.getElementById("layers").value);

    const leadTimes = ["submit1", "submit3", "submit5", "submit7", "submit10", "submit20"];

    leadTimes.forEach(id => {
        const row = document.getElementById(id)?.closest(".form-group");
        if (row) row.style.display = "block";
    });
	
	if (layers >= 4 && layers <= 10) {
        hideLeadTimes(["submit1","submit3","submit5","submit7","submit10"]);
        // still allow submit20 (unless area/contact rule hides everything)
        removeContactMessage();
        return; // stop further area-based hiding (optional — prevents overriding)
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
        const row = document.getElementById(id)?.closest(".form-group");
        if (row) row.style.display = "none";
    });
}

function hideAllLeadTimes(ids) {
    ids.forEach(id => hideLeadTimes([id]));
}

function displayContactMessage() {
    let messageDiv = document.getElementById("contactMessage");
    if (!messageDiv) {
        messageDiv = document.createElement("div");
        messageDiv.id = "contactMessage";
        messageDiv.style.padding = "20px";
        messageDiv.style.margin = "20px 0";
        messageDiv.style.backgroundColor = "#f8d7da";
        messageDiv.style.color = "#721c24";
        messageDiv.style.border = "1px solid #f5c6cb";
        messageDiv.style.borderRadius = "5px";
        messageDiv.style.textAlign = "center";
        messageDiv.innerHTML = `<strong>Note:</strong> For larger orders, please contact us at <a href="tel:9898842942">9898842942</a> or <a href="tel:8160282840">8160282840</a>.`;
        document.querySelector(".pcb-order-block").prepend(messageDiv);
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









