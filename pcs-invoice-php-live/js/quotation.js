(function () {
    'use strict';

    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
        } else {
            callback();
        }
    }

    function number(value) {
        var parsed = parseFloat(value);
        return isNaN(parsed) ? 0 : parsed;
    }

    function round(value) {
        return Math.round((value + Number.EPSILON) * 100) / 100;
    }

    ready(function () {
        var form = document.getElementById('quotationForm');
        if (!form) {
            return;
        }

        var tbody = document.getElementById('quotationItemsBody');
        var template = document.getElementById('quotationItemTemplate');
        var addButton = document.getElementById('addRows');
        var removeButton = document.getElementById('removeRows');
        var checkAllBox = document.getElementById('checkAll');
        var errorBox = document.getElementById('quotationFormErrors');
        var symbol = form.getAttribute('data-currency-symbol') || '\u20B9';
        var customerNameInput = form.querySelector('[name="customer_name"]');
        var customerList = document.getElementById('quotationCustomerSuggestions');
        var customerOptionsBox = document.getElementById('quotationCustomerOptions');
        var customerSuggestions = [];
        var lastFilledCustomer = '';
        var activeCustomerIndex = -1;

        if (customerList) {
            for (var suggestionIndex = 0; suggestionIndex < customerList.options.length; suggestionIndex++) {
                var suggestion = customerList.options[suggestionIndex];
                var suggestionName = (suggestion.value || '').trim();
                if (suggestionName === '') {
                    continue;
                }
                customerSuggestions.push({
                    name: suggestionName,
                    phone: suggestion.getAttribute('data-phone') || '',
                    email: suggestion.getAttribute('data-email') || '',
                    address: suggestion.getAttribute('data-address') || ''
                });
            }
        }

        function hideCustomerOptions() {
            if (customerOptionsBox) {
                customerOptionsBox.hidden = true;
                customerOptionsBox.innerHTML = '';
            }
            activeCustomerIndex = -1;
        }

        function selectCustomer(customer) {
            if (!customer) {
                return;
            }
            if (customerNameInput) {
                customerNameInput.value = customer.name;
            }
            lastFilledCustomer = customer.name;
            ['phone', 'email', 'address'].forEach(function (field) {
                var input = form.querySelector('[name="customer_' + field + '"]');
                if (input) {
                    input.value = customer[field] || '';
                }
            });
            hideCustomerOptions();
        }

        function matchingCustomers(term) {
            var query = term.toLowerCase();
            return customerSuggestions.filter(function (customer) {
                return query === '' || customer.name.toLowerCase().indexOf(query) !== -1;
            });
        }

        function renderCustomerOptions() {
            if (!customerOptionsBox) {
                return;
            }

            var matches = matchingCustomers(customerNameInput ? customerNameInput.value.trim() : '');
            customerOptionsBox.innerHTML = '';
            activeCustomerIndex = -1;

            if (!matches.length) {
                customerOptionsBox.hidden = true;
                return;
            }

            matches.slice(0, 8).forEach(function (customer, index) {
                var option = document.createElement('button');
                option.type = 'button';
                option.className = 'quotation-customer-option';
                option.setAttribute('data-index', String(index));

                var nameSpan = document.createElement('span');
                nameSpan.className = 'quotation-customer-option-name';
                nameSpan.textContent = customer.name;
                option.appendChild(nameSpan);

                var meta = [customer.phone, customer.email].filter(function (value) {
                    return value !== '';
                }).join(' | ');
                if (meta !== '') {
                    var metaSpan = document.createElement('small');
                    metaSpan.textContent = meta;
                    option.appendChild(metaSpan);
                }

                option.addEventListener('click', function () {
                    selectCustomer(customer);
                });

                customerOptionsBox.appendChild(option);
            });

            customerOptionsBox.hidden = false;
        }

        function highlightCustomerOption(direction) {
            if (!customerOptionsBox || customerOptionsBox.hidden) {
                return;
            }
            var options = customerOptionsBox.querySelectorAll('.quotation-customer-option');
            if (!options.length) {
                return;
            }
            activeCustomerIndex = (activeCustomerIndex + direction + options.length) % options.length;
            for (var i = 0; i < options.length; i++) {
                options[i].classList.toggle('is-active', i === activeCustomerIndex);
            }
            options[activeCustomerIndex].scrollIntoView({ block: 'nearest' });
        }

        // Keep focus on the input when clicking inside the dropdown so the
        // click event still reaches the option button.
        if (customerOptionsBox) {
            customerOptionsBox.addEventListener('mousedown', function (event) {
                event.preventDefault();
            });
        }

        if (customerNameInput) {
            // The native datalist is only a no-JS fallback. The custom panel
            // below replaces it so both dropdowns never open at the same time.
            if (customerOptionsBox) {
                customerNameInput.removeAttribute('list');
            }
            customerNameInput.addEventListener('focus', renderCustomerOptions);
            customerNameInput.addEventListener('click', renderCustomerOptions);
            customerNameInput.addEventListener('input', function () {
                lastFilledCustomer = '';
                renderCustomerOptions();
            });
            customerNameInput.addEventListener('keydown', function (event) {
                if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                    if (customerOptionsBox && customerOptionsBox.hidden) {
                        renderCustomerOptions();
                    }
                    highlightCustomerOption(event.key === 'ArrowDown' ? 1 : -1);
                    event.preventDefault();
                } else if (event.key === 'Enter' && activeCustomerIndex >= 0) {
                    var matches = matchingCustomers(customerNameInput.value.trim());
                    selectCustomer(matches[activeCustomerIndex]);
                    event.preventDefault();
                } else if (event.key === 'Escape') {
                    hideCustomerOptions();
                }
            });
            customerNameInput.addEventListener('change', function () {
                var name = customerNameInput.value.trim();
                if (name === '' || name === lastFilledCustomer) {
                    return;
                }
                for (var i = 0; i < customerSuggestions.length; i++) {
                    if (customerSuggestions[i].name === name) {
                        selectCustomer(customerSuggestions[i]);
                        return;
                    }
                }
            });
        }

        document.addEventListener('click', function (event) {
            if (!customerOptionsBox || customerOptionsBox.hidden) {
                return;
            }
            if (customerOptionsBox.contains(event.target)
                || (customerNameInput && event.target === customerNameInput)) {
                return;
            }
            hideCustomerOptions();
        });

        if (!tbody || !template) {
            return;
        }

        function money(value) {
            return symbol + ' ' + round(value).toFixed(2);
        }

        function setText(id, value) {
            var element = document.getElementById(id);
            if (element) {
                element.textContent = value;
            }
        }

        function rows() {
            return Array.prototype.slice.call(tbody.querySelectorAll('.item-row'));
        }

        function renumberRows() {
            rows().forEach(function (row, index) {
                var checkbox = row.querySelector('.itemRow');
                if (checkbox) {
                    checkbox.id = 'quotationItemRow_' + (index + 1);
                    checkbox.checked = false;
                }
            });
            syncCheckAll();
        }

        function syncCheckAll() {
            var checkAll = document.getElementById('checkAll');
            if (!checkAll) {
                return;
            }
            var allRows = rows();
            var selected = allRows.filter(function (row) {
                var checkbox = row.querySelector('.itemRow');
                return checkbox && checkbox.checked;
            });
            checkAll.checked = allRows.length > 0 && selected.length === allRows.length;
        }

        function removeSelectedRows() {
            var allRows = rows();
            var selected = allRows.filter(function (row) {
                var checkbox = row.querySelector('.itemRow');
                return checkbox && checkbox.checked;
            });
            if (!selected.length) {
                return;
            }

            if (selected.length >= allRows.length) {
                // Keep one editable row, exactly like invoice creation.
                allRows.slice(1).forEach(function (row) {
                    row.remove();
                });
                resetRow(allRows[0]);
            } else {
                selected.forEach(function (row) {
                    row.remove();
                });
            }

            var checkAll = document.getElementById('checkAll');
            if (checkAll) {
                checkAll.checked = false;
            }
            renumberRows();
            calculateTotals();
        }

        function calculateRow(row) {
            var quantityInput = row.querySelector('.item-qty');
            var priceInput = row.querySelector('.item-price');
            var discountInput = row.querySelector('.item-discount');
            var lineTotalCell = row.querySelector('.line-total');

            var quantity = number(quantityInput && quantityInput.value);
            var price = number(priceInput && priceInput.value);
            var discount = number(discountInput && discountInput.value);

            var gross = round((quantity > 0 ? quantity : 1) * price);
            var appliedDiscount = Math.min(Math.max(discount, 0), gross);
            var lineTotal = round(Math.max(0, gross - appliedDiscount));

            if (lineTotalCell) {
                lineTotalCell.textContent = money(lineTotal);
            }

            return {
                gross: gross,
                discount: appliedDiscount
            };
        }

        function gstEnabled() {
            var checked = form.querySelector('[name="gst_type"]:checked');
            return !checked || checked.value === 'with_gst';
        }

        function selectedCurrency() {
            var checked = form.querySelector('[name="currency"]:checked');
            return checked && checked.value === 'USD' ? 'USD' : 'INR';
        }

        function updateCurrencyDisplay() {
            symbol = selectedCurrency() === 'USD' ? '$' : '\u20B9';
            form.setAttribute('data-currency-symbol', symbol);
        }

        // USD quotations never carry GST, exactly like invoice creation.
        function applyCurrencyRules() {
            var gstWrapper = document.getElementById('quotation-gst-toggle-wrapper');
            if (selectedCurrency() === 'USD') {
                var withoutGst = document.getElementById('quotation_without_gst');
                if (withoutGst) {
                    withoutGst.checked = true;
                }
                if (gstWrapper) {
                    gstWrapper.style.display = 'none';
                }
            } else if (gstWrapper) {
                gstWrapper.style.display = '';
            }
        }

        function updateBankDetails() {
            var bankDetails = document.getElementById('quotationBankDetails');
            if (!bankDetails) {
                return;
            }

            var dataKey = selectedCurrency() === 'USD'
                ? 'data-usd'
                : (gstEnabled() ? 'data-inr-with-gst' : 'data-inr-without-gst');
            var details = JSON.parse(bankDetails.getAttribute(dataKey) || '{}');
            var rows = [];

            Object.keys(details).forEach(function (label) {
                var row = document.createElement('div');
                row.className = 'small mb-1';
                var heading = document.createElement('strong');
                heading.textContent = label + ': ';
                row.appendChild(heading);
                row.appendChild(document.createTextNode(details[label]));
                rows.push(row);
            });

            while (bankDetails.firstChild) {
                bankDetails.removeChild(bankDetails.firstChild);
            }
            rows.forEach(function (row) {
                bankDetails.appendChild(row);
            });
        }

        function updateItemTaxRates() {
            var rate = gstEnabled() ? '18' : '0';
            rows().forEach(function (row) {
                var taxInput = row.querySelector('.item-tax');
                if (taxInput) {
                    taxInput.value = rate;
                }
            });
        }

        function calculateTotals() {
            updateCurrencyDisplay();
            updateItemTaxRates();
            var subtotal = 0;
            var discount = 0;

            rows().forEach(function (row) {
                var totals = calculateRow(row);
                subtotal += totals.gross;
                discount += totals.discount;
            });

            subtotal = round(subtotal);
            discount = round(discount);
            var taxableTotal = round(subtotal - discount);
            var cgst = gstEnabled() ? round(taxableTotal * 0.09) : 0;
            var sgst = gstEnabled() ? round(taxableTotal * 0.09) : 0;
            var grandTotal = round(taxableTotal + cgst + sgst);

            setText('summarySubtotal', money(subtotal));
            setText('summaryDiscount', money(discount));
            setText('summaryCgst', money(cgst));
            setText('summarySgst', money(sgst));
            setText('summaryGrandTotal', money(grandTotal));

            var cgstRow = document.getElementById('summaryCgstRow');
            var sgstRow = document.getElementById('summarySgstRow');
            if (cgstRow) {
                cgstRow.style.display = gstEnabled() ? '' : 'none';
            }
            if (sgstRow) {
                sgstRow.style.display = gstEnabled() ? '' : 'none';
            }
        }

        function addRow(values) {
            var fragment = template.content.cloneNode(true);
            var row = fragment.querySelector('.item-row');

            if (values) {
                ['product_name', 'quantity', 'unit_price', 'discount', 'tax_percent'].forEach(function (field) {
                    var input = row.querySelector('[data-field="' + field + '"]');
                    if (input && values[field] !== undefined && values[field] !== null) {
                        input.value = values[field];
                    }
                });
            }

            tbody.appendChild(fragment);
            renumberRows();
            calculateTotals();
        }

        function resetRow(row) {
            row.querySelectorAll('input, textarea').forEach(function (input) {
                if (input.type === 'checkbox' || input.type === 'radio') {
                    input.checked = false;
                } else {
                    input.value = '';
                }
            });
        }

        function showErrors(errors) {
            if (!errorBox) {
                return;
            }
            if (!errors.length) {
                errorBox.classList.add('d-none');
                errorBox.innerHTML = '';
                return;
            }

            var list = document.createElement('ul');
            list.className = 'mb-0';
            errors.forEach(function (message) {
                var item = document.createElement('li');
                item.textContent = message;
                list.appendChild(item);
            });

            errorBox.classList.remove('d-none');
            errorBox.innerHTML = '';
            errorBox.appendChild(list);
            errorBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        function validateForm(isSending) {
            var errors = [];
            var customerName = form.querySelector('[name="customer_name"]');
            var customerPhone = form.querySelector('[name="customer_phone"]');
            var customerEmail = form.querySelector('[name="customer_email"]');
            var quotationDate = form.querySelector('[name="quotation_date"]');
            var validUntil = form.querySelector('[name="valid_until"]');

            if (!customerName || customerName.value.trim() === '') {
                errors.push('Customer name is required.');
            }

            if (customerPhone && customerPhone.value.trim() !== ''
                && !/^[0-9+\-\s().]{6,20}$/.test(customerPhone.value.trim())) {
                errors.push('Customer phone number is not valid.');
            }

            if (customerEmail && customerEmail.value.trim() !== ''
                && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(customerEmail.value.trim())) {
                errors.push('Customer email address is not valid.');
            }

            if (isSending && (!customerEmail || customerEmail.value.trim() === '')) {
                errors.push('Customer email is required to send this quotation.');
            }

            if (!quotationDate || !quotationDate.value) {
                errors.push('A valid quotation date is required.');
            }

            if (!validUntil || !validUntil.value) {
                errors.push('A valid "valid until" date is required.');
            } else if (quotationDate && quotationDate.value && validUntil.value < quotationDate.value) {
                errors.push('"Valid until" cannot be earlier than the quotation date.');
            }

            var hasItem = false;
            var itemRows = rows();

            itemRows.forEach(function (row) {
                var name = row.querySelector('.item-name');
                var quantity = row.querySelector('.item-qty');
                var price = row.querySelector('.item-price');
                var discount = row.querySelector('.item-discount');

                var nameValue = name ? name.value.trim() : '';
                var quantityValue = number(quantity && quantity.value);
                var quantityRaw = quantity ? quantity.value.trim() : '';
                var priceValue = price ? price.value.trim() : '';
                var priceNumber = number(priceValue);
                var discountValue = number(discount && discount.value);

                if (nameValue === '' && quantityValue === 0
                    && (priceValue === '' || priceNumber === 0) && discountValue === 0) {
                    return;
                }

                hasItem = true;

                if (nameValue === '') {
                    errors.push('Every item row needs a description.');
                }
                if (quantityRaw !== '' && quantityValue < 0) {
                    errors.push('Quantity must be zero or greater for all items.');
                }
                if (priceValue !== '' && priceNumber < 0) {
                    errors.push('Unit price must be zero or greater for all items.');
                }
                if (discount && discount.value.trim() !== '' && discountValue < 0) {
                    errors.push('Discount must be zero or greater for all items.');
                }
                if (discountValue > round((quantityValue > 0 ? quantityValue : 1) * priceNumber)) {
                    errors.push('Discount cannot be greater than the item amount.');
                }
            });

            if (!hasItem) {
                errors.push('Add at least one item.');
            }

            // Remove duplicate messages so the alert stays readable.
            errors = errors.filter(function (message, index) {
                return errors.indexOf(message) === index;
            });

            showErrors(errors);
            return errors.length === 0;
        }

        tbody.addEventListener('input', function (event) {
            if (event.target.matches('.item-qty, .item-price, .item-discount')) {
                calculateTotals();
            }
        });

        form.querySelectorAll('[name="gst_type"]').forEach(function (input) {
            input.addEventListener('change', function () {
                calculateTotals();
                updateBankDetails();
            });
        });

        form.querySelectorAll('[name="currency"]').forEach(function (input) {
            input.addEventListener('change', function () {
                applyCurrencyRules();
                calculateTotals();
                updateBankDetails();
            });
        });

        tbody.addEventListener('change', function (event) {
            if (event.target.matches('.itemRow')) {
                syncCheckAll();
            }
        });

        if (checkAllBox) {
            checkAllBox.addEventListener('change', function () {
                rows().forEach(function (row) {
                    var checkbox = row.querySelector('.itemRow');
                    if (checkbox) {
                        checkbox.checked = checkAllBox.checked;
                    }
                });
            });
        }

        if (addButton) {
            addButton.addEventListener('click', function () {
                addRow();
            });
        }

        if (removeButton) {
            removeButton.addEventListener('click', removeSelectedRows);
        }

        form.addEventListener('submit', function (event) {
            var submitter = event.submitter || null;
            var isSending = !!(submitter
                && submitter.name === 'quotation_action'
                && submitter.value === 'send_quotation');
            if (!validateForm(isSending)) {
                event.preventDefault();
            }
        });

        if (rows().length === 0) {
            addRow();
        }

        renumberRows();
        applyCurrencyRules();
        updateBankDetails();
        calculateTotals();
    });
}());
