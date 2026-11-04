$(function () {
    bindInvoiceEvents();
    initializeInvoiceState();
    initializeCustomerAutocomplete();
});

function getInvoiceFormCurrency() {
    const $form = $('#invoice-form');
    if (!$form.length) {
        return 'INR';
    }
    const $radioChecked = $form.find('input[name="currency"][type="radio"]:checked');
    if ($radioChecked.length) {
        const v = ($radioChecked.val() || '').trim().toUpperCase();
        return v === 'USD' ? 'USD' : 'INR';
    }
    const $currencySelect = $form.find('select[name="currency"]');
    if ($currencySelect.length) {
        const v = ($currencySelect.val() || '').trim().toUpperCase();
        return v === 'USD' ? 'USD' : 'INR';
    }
    return 'INR';
}

function bindInvoiceEvents() {
    $(document).on('change', '#invoice-form input[name="currency"], #invoice-form select[name="currency"]', onCurrencyChange);
    $(document).on('change', '#invoice-form input[name="gst_type"]', onGstTypeChange);
    $(document).on('input change blur', "#invoiceItem input[name='price[]'], #invoiceItem input[name='taxableValue[]']", calculateTotal);
    $('#invoice-form').on('submit', function () {
        syncAllLineTaxableFromPrice();
        calculateTotal();
    });
    $(document).on('click', '#addRows', function () {
        addInvoiceRow();
    });
    $(document).on('click', '#removeRows', function () {
        removeSelectedRows();
    });
    $(document).on('change', '#checkAll', function () {
        $('.itemRow').prop('checked', $(this).is(':checked'));
    });
    $(document).on('change', '.itemRow', function () {
        syncCheckAll();
    });
    $(document).on('click', '#addRows, #removeRows', function () {
        setTimeout(function () {
            calculateTotal();
            updateGstVisibility();
        }, 100);
    });
}

function getNextRowIndex() {
    let maxIndex = 0;
    $("[id^='productName_']").each(function () {
        const currentId = $(this).attr('id') || '';
        const index = parseInt(currentId.replace('productName_', ''), 10);
        if (!Number.isNaN(index) && index > maxIndex) {
            maxIndex = index;
        }
    });
    return maxIndex + 1;
}

function getInvoiceMonthSelectHtml(rowNumber) {
    const months = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'
    ];
    let options = '<option value="">— Select —</option>';
    months.forEach(function (m) {
        const esc = $('<div>').text(m).html();
        options += '<option value="' + esc + '">' + esc + '</option>';
    });
    return (
        '<select name="itemMonth[]" id="itemMonth_' + rowNumber + '" class="form-select form-select-sm" autocomplete="off">' +
        options +
        '</select>'
    );
}

function addInvoiceRow() {
    const rowNumber = getNextRowIndex();
    const rowHtml = `
        <tr>
            <td>
                <div class="custom-control custom-checkbox">
                    <input type="checkbox" class="form-check-input itemRow" id="itemRow_${rowNumber}">
                </div>
            </td>
            <td><input type="text" name="productName[]" id="productName_${rowNumber}" class="form-control" autocomplete="off"></td>
            <td><input type="text" name="productCode[]" id="productCode_${rowNumber}" class="form-control" value="998314" autocomplete="off"></td>
            <td><input type="number" name="price[]" id="price_${rowNumber}" class="form-control price" min="0" step="any" autocomplete="off"></td>
            <td><input type="number" name="taxableValue[]" id="taxableValue_${rowNumber}" class="form-control taxableValue" min="0" step="any" autocomplete="off"></td>
            <td>${getInvoiceMonthSelectHtml(rowNumber)}</td>
        </tr>
    `;

    $('#invoiceItem tbody').append(rowHtml);
    $('#checkAll').prop('checked', false);
}

function removeSelectedRows() {
    const $rows = $('#invoiceItem tbody tr');
    const selectedCount = $rows.find('.itemRow:checked').length;
    if (selectedCount === 0) {
        return;
    }

    if (selectedCount >= $rows.length) {
        // Keep at least one editable row in the invoice table.
        const $firstRow = $rows.first();
        $rows.not($firstRow).remove();
        resetRowInputs($firstRow, 1);
    } else {
        $rows.each(function () {
            const $row = $(this);
            if ($row.find('.itemRow').is(':checked')) {
                $row.remove();
            }
        });
        renumberInvoiceRows();
    }

    $('#checkAll').prop('checked', false);
}

/** Re-index row IDs after delete so price_N matches taxableValue_N in the same row. */
function renumberInvoiceRows() {
    $('#invoiceItem tbody tr').each(function (index) {
        const rowNumber = index + 1;
        const $row = $(this);
        $row.find('.itemRow').attr('id', 'itemRow_' + rowNumber);
        $row.find("input[name='productName[]']").attr('id', 'productName_' + rowNumber);
        $row.find("input[name='productCode[]']").attr('id', 'productCode_' + rowNumber);
        $row.find("input[name='price[]']").attr('id', 'price_' + rowNumber);
        $row.find("input[name='taxableValue[]']").attr('id', 'taxableValue_' + rowNumber);
        $row.find("select[name='itemMonth[]']").attr('id', 'itemMonth_' + rowNumber);
    });
}

/** Parse amount from number/text input (handles commas in values like 6,900). */
function parseAmount(value) {
    if (value === null || value === undefined) {
        return 0;
    }
    const cleaned = String(value).replace(/,/g, '').trim();
    if (cleaned === '') {
        return 0;
    }
    const num = parseFloat(cleaned);
    return Number.isFinite(num) ? num : 0;
}

function formatAmount(value) {
    const num = parseAmount(value);
    return num > 0 ? num.toFixed(2) : '';
}

function resetRowInputs($row, index) {
    $row.find('.itemRow').prop('checked', false).attr('id', 'itemRow_' + index);
    $row.find("input[name='productName[]']").val('').attr('id', 'productName_' + index);
    $row.find("input[name='productCode[]']").val('998314').attr('id', 'productCode_' + index);
    $row.find("input[name='price[]']").val('').attr('id', 'price_' + index);
    $row.find("input[name='taxableValue[]']").val('').attr('id', 'taxableValue_' + index);
    const $monthCell = $row.find('td').has("select[name='itemMonth[]']").first();
    if ($monthCell.length) {
        $monthCell.html(getInvoiceMonthSelectHtml(index));
    }
}

function syncCheckAll() {
    const $rows = $('.itemRow');
    if (!$rows.length) {
        $('#checkAll').prop('checked', false);
        return;
    }
    $('#checkAll').prop('checked', $rows.length === $('.itemRow:checked').length);
}

function onCurrencyChange() {
    updateCurrencyVisibility();
    calculateTotal();
}

function onGstTypeChange() {
    updateGstFieldsVisibility();
    calculateTotal();
}

function initializeInvoiceState() {
    const $form = $('#invoice-form');
    if (!$form.length) {
        return;
    }
    if (!$form.find('input[name="currency"], select[name="currency"]').length) {
        return;
    }

    if (getInvoiceFormCurrency() === 'USD') {
        $form.find('#without_gst').prop('checked', true);
    } else if (!$form.find('input[name="gst_type"]:checked').length) {
        $form.find('#with_gst').prop('checked', true);
    }

    updateCurrencyVisibility();
    updateGstFieldsVisibility();
    calculateTotal();
}

function updateCurrencyVisibility() {
    const $form = $('#invoice-form');
    if (!$form.length) {
        return;
    }

    const currency = getInvoiceFormCurrency();
    const isInr = currency === 'INR';
    const $gstToggle = $form.find('#gst-toggle-wrapper');

    $form.find('.currency-symbol').text(isInr ? '₹' : '$');

    if (isInr) {
        $gstToggle.show();
    } else {
        $gstToggle.hide();
        $form.find('#without_gst').prop('checked', true);
    }
}

function updateGstFieldsVisibility() {
    const $form = $('#invoice-form');
    if (!$form.length) {
        return;
    }

    const currency = getInvoiceFormCurrency();
    const gstType = ($form.find('input[name="gst_type"]:checked').val() || 'with_gst');
    const isInr = currency === 'INR';
    const showFields = isInr && gstType === 'with_gst';
    const $gstFields = $form.find('#gst-fields-wrapper');

    if (showFields) {
        $gstFields.show();
    } else {
        $gstFields.hide();
        $form.find('#cgstTax, #sgstTax, #cgstAmount, #sgstAmount').val(0);
    }
}

function updateGstVisibility() {
    updateCurrencyVisibility();
    updateGstFieldsVisibility();
}

/** Copy Price → Taxable Value for each line (Price is the billing amount). */
function syncAllLineTaxableFromPrice() {
    $('#invoiceItem tbody tr').each(function () {
        const $row = $(this);
        const $priceInput = $row.find("input[name='price[]']").first();
        const $taxableInput = $row.find("input[name='taxableValue[]']").first();
        const price = parseAmount($priceInput.val());
        if (price > 0) {
            $taxableInput.val(formatAmount(price));
        }
    });
}

function calculateTotal() {
    syncAllLineTaxableFromPrice();

    let subtotal = 0;

    $('#invoiceItem tbody tr').each(function () {
        const $row = $(this);
        const price = parseAmount($row.find("input[name='price[]']").first().val());
        const taxableValue = parseAmount($row.find("input[name='taxableValue[]']").first().val());
        const lineAmount = taxableValue > 0 ? taxableValue : price;
        subtotal += lineAmount;
    });

    $('#rate').val(subtotal.toFixed(2));

    const currency = getInvoiceFormCurrency();
    const $form = $('#invoice-form');
    const gstType = ($form.length ? $form.find('input[name="gst_type"]:checked').val() : null) || 'with_gst';

    if (currency === 'INR' && gstType === 'with_gst') {
        const cgstRate = 9;
        const sgstRate = 9;
        const cgstAmount = subtotal * cgstRate / 100;
        const sgstAmount = subtotal * sgstRate / 100;

        $('#cgstTax').val(cgstRate);
        $('#sgstTax').val(sgstRate);
        $('#cgstAmount').val(cgstAmount.toFixed(2));
        $('#sgstAmount').val(sgstAmount.toFixed(2));
        $('#total').val((subtotal + cgstAmount + sgstAmount).toFixed(2));
    } else {
        $('#cgstTax').val(0);
        $('#sgstTax').val(0);
        $('#cgstAmount').val('0.00');
        $('#sgstAmount').val('0.00');
        $('#total').val(subtotal.toFixed(2));
    }
}

function initializeCustomerAutocomplete() {
    const $nameInput = $('#receiverName, input[name="receiverName"]').first();
    if (!$nameInput.length) {
        return;
    }

    injectAutocompleteStyles();

    const $container = $nameInput.closest('.customer-autocomplete-wrapper, .form-group');
    $container.css('position', 'relative');

    $('#customerSuggestDropdown').remove();
    const $dropdown = $('<div id="customerSuggestDropdown" class="customer-suggest-dropdown d-none" role="listbox" aria-label="Customer suggestions"></div>');
    $container.append($dropdown);

    let activeIndex = -1;
    let suggestions = [];
    let debounceTimer = null;
    let activeRequest = null;

    function hideDropdown() {
        $dropdown.addClass('d-none').empty();
        suggestions = [];
        activeIndex = -1;
    }

    function fillCustomerDetails(customer) {
        $('textarea[name="receiverAddress"]').val(customer.receiver_address || '');
        $('input[name="gstNo"]').val(customer.gst_no || '');
        $('input[name="panNo"]').val(customer.pan_no || '');
        $('input[name="supply"]').val(customer.supply || '');
    }

    function renderSuggestions(items) {
        if (!items || !items.length) {
            hideDropdown();
            return;
        }

        suggestions = items;
        activeIndex = -1;
        $dropdown.empty();

        items.forEach(function (customer, index) {
            const safeName = $('<div>').text(customer.receiver_name || '').html();
            const safeAddress = $('<div>').text(customer.receiver_address || '').html();
            const safePan = $('<div>').text(customer.pan_no || '').html();
            const safeGst = $('<div>').text(customer.gst_no || '').html();
            const itemHtml = [
                '<div class="customer-suggest-item" data-index="' + index + '" role="option" aria-selected="false">',
                '<div class="customer-suggest-name">' + safeName + '</div>',
                safePan ? '<div class="customer-suggest-meta">PAN: ' + safePan + '</div>' : '<div class="customer-suggest-meta">PAN: -</div>',
                safeGst ? '<div class="customer-suggest-meta">GST: ' + safeGst + '</div>' : '<div class="customer-suggest-meta">GST: -</div>',
                safeAddress ? '<div class="customer-suggest-meta">' + safeAddress + '</div>' : '',
                '</div>'
            ].join('');
            $dropdown.append(itemHtml);
        });

        $dropdown.removeClass('d-none');
    }

    function setActiveItem(index) {
        const $items = $dropdown.find('.customer-suggest-item');
        $items.removeClass('active').attr('aria-selected', 'false');
        if (index >= 0 && index < $items.length) {
            $items.eq(index).addClass('active').attr('aria-selected', 'true');
            activeIndex = index;
        } else {
            activeIndex = -1;
        }
    }

    function fetchSuggestions(query) {
        if (activeRequest && activeRequest.readyState !== 4) {
            activeRequest.abort();
        }
        activeRequest = $.ajax({
            url: 'customer_suggest.php',
            type: 'GET',
            dataType: 'json',
            cache: false,
            data: { q: query },
            success: function (response) {
                let parsed = response;
                if (typeof response === 'string') {
                    try {
                        parsed = JSON.parse(response);
                    } catch (e) {
                        hideDropdown();
                        return;
                    }
                }
                if (!parsed || Number(parsed.status) !== 1 || !Array.isArray(parsed.customers)) {
                    hideDropdown();
                    return;
                }
                renderSuggestions(parsed.customers);
            },
            error: function () {
                hideDropdown();
            }
        });
    }

    $nameInput.on('input keyup', function () {
        const query = ($(this).val() || '').trim();
        if (debounceTimer) {
            clearTimeout(debounceTimer);
        }
        if (query.length === 0) {
            hideDropdown();
            return;
        }
        debounceTimer = setTimeout(function () {
            fetchSuggestions(query);
        }, 250);
    });

    $nameInput.on('keydown', function (e) {
        if ($dropdown.hasClass('d-none')) {
            return;
        }
        const maxIndex = suggestions.length - 1;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            const next = activeIndex < maxIndex ? activeIndex + 1 : 0;
            setActiveItem(next);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            const prev = activeIndex > 0 ? activeIndex - 1 : maxIndex;
            setActiveItem(prev);
        } else if (e.key === 'Enter') {
            const selectedIndex = activeIndex >= 0 ? activeIndex : 0;
            if (suggestions[selectedIndex]) {
                e.preventDefault();
                const selected = suggestions[selectedIndex];
                $nameInput.val(selected.receiver_name || '');
                fillCustomerDetails(selected);
                hideDropdown();
            }
        } else if (e.key === 'Escape') {
            hideDropdown();
        }
    });

    $dropdown.on('mousedown', '.customer-suggest-item', function (e) {
        e.preventDefault();
        const index = parseInt($(this).attr('data-index'), 10);
        if (Number.isNaN(index) || !suggestions[index]) {
            return;
        }
        const selected = suggestions[index];
        $nameInput.val(selected.receiver_name || '');
        fillCustomerDetails(selected);
        hideDropdown();
    });

    $nameInput.on('blur', function () {
        setTimeout(hideDropdown, 150);
    });

    $(document).on('click', function (e) {
        if (!$(e.target).closest($container).length) {
            hideDropdown();
        }
    });
}

function injectAutocompleteStyles() {
    if ($('#customerSuggestStyles').length) {
        return;
    }

    const css = `
        <style id="customerSuggestStyles">
            .customer-suggest-dropdown {
                position: absolute;
                top: calc(100% + 4px);
                left: 0;
                width: 100%;
                max-height: 220px;
                overflow-y: auto;
                background: #fff;
                border: 1px solid #ced4da;
                border-radius: 8px;
                box-shadow: 0 8px 20px rgba(0,0,0,0.12);
                z-index: 9999;
            }
            .customer-suggest-item {
                padding: 10px 12px;
                cursor: pointer;
                border-bottom: 1px solid #f1f3f5;
            }
            .customer-suggest-item:last-child {
                border-bottom: none;
            }
            .customer-suggest-item:hover,
            .customer-suggest-item.active {
                background: #eef4ff;
            }
            .customer-suggest-name {
                font-weight: 600;
                color: #1e3c72;
                margin-bottom: 2px;
            }
            .customer-suggest-meta {
                font-size: 12px;
                color: #6c757d;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
        </style>
    `;

    $('head').append(css);
}
