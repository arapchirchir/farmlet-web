<script>
    (function() {
        const countySelects = document.querySelectorAll('[data-location="county"]');
        if (!countySelects.length) {
            return;
        }

        function setOptions(select, items, placeholder, selectedId) {
            if (!select) return;

            select.innerHTML = '';
            const placeholderOption = document.createElement('option');
            placeholderOption.value = '';
            placeholderOption.textContent = placeholder;
            select.appendChild(placeholderOption);

            items.forEach((item) => {
                const option = document.createElement('option');
                option.value = item.id;
                option.textContent = item.name;
                select.appendChild(option);
            });

            if (selectedId) {
                select.value = String(selectedId);
            }

            if (window.$ && $(select).hasClass('select2-hidden-accessible')) {
                $(select).trigger('change.select2');
            }
        }

        async function fetchSubcounties(countySelect, subcountySelect, wardSelect) {
            const subcountiesUrl = countySelect.dataset.subcountiesUrl;
            const wardsUrl = countySelect.dataset.wardsUrl;
            const countyId = countySelect.value;
            const subcountySelected = subcountySelect?.dataset.selected || '';
            const wardSelected = wardSelect?.dataset.selected || '';
            const subcountyPlaceholder = subcountySelect?.dataset.defaultOption || 'Select Sub-County';
            const wardPlaceholder = wardSelect?.dataset.defaultOption || 'Select Ward';

            if (!subcountiesUrl) return;
            if (!countyId) {
                setOptions(subcountySelect, [], subcountyPlaceholder, '');
                setOptions(wardSelect, [], wardPlaceholder, '');
                return;
            }

            const response = await fetch(`${subcountiesUrl}?county_id=${countyId}`);
            const data = await response.json();
            setOptions(subcountySelect, data, subcountyPlaceholder, subcountySelected);

            if (subcountySelect?.value) {
                await fetchWards(wardsUrl, subcountySelect, wardSelect, wardSelected);
            } else {
                setOptions(wardSelect, [], wardPlaceholder, '');
            }
        }

        async function fetchWards(wardsUrl, subcountySelect, wardSelect, selectedId) {
            const subcountyId = subcountySelect?.value;
            const wardPlaceholder = wardSelect?.dataset.defaultOption || 'Select Ward';

            if (!wardsUrl || !wardSelect) return;
            if (!subcountyId) {
                setOptions(wardSelect, [], wardPlaceholder, '');
                return;
            }

            const response = await fetch(`${wardsUrl}?subcounty_id=${subcountyId}`);
            const data = await response.json();
            setOptions(wardSelect, data, wardPlaceholder, selectedId);
        }

        function bindSelects(countySelect) {
            const scope = countySelect.closest('form') || document;
            const subcountySelect = scope.querySelector('[data-location="subcounty"]');
            const wardSelect = scope.querySelector('[data-location="ward"]');
            const wardsUrl = countySelect.dataset.wardsUrl;

            if (!subcountySelect) return;

            const onCountyChange = () => fetchSubcounties(countySelect, subcountySelect, wardSelect);
            const onSubcountyChange = () => fetchWards(wardsUrl, subcountySelect, wardSelect, '');

            countySelect.addEventListener('change', onCountyChange);
            subcountySelect.addEventListener('change', onSubcountyChange);

            if (window.$) {
                $(countySelect).on('select2:select', onCountyChange);
                $(subcountySelect).on('select2:select', onSubcountyChange);
            }

            if (countySelect.value) {
                fetchSubcounties(countySelect, subcountySelect, wardSelect);
            } else {
                setOptions(subcountySelect, [], subcountySelect.dataset.defaultOption || 'Select Sub-County', '');
                setOptions(wardSelect, [], wardSelect?.dataset.defaultOption || 'Select Ward', '');
            }
        }

        countySelects.forEach(bindSelects);

        if (window.$) {
            $(document).on('change', '[data-location="county"]', function() {
                const countySelect = this;
                const scope = countySelect.closest('form') || document;
                const subcountySelect = scope.querySelector('[data-location="subcounty"]');
                const wardSelect = scope.querySelector('[data-location="ward"]');
                fetchSubcounties(countySelect, subcountySelect, wardSelect);
            });

            $(document).on('change', '[data-location="subcounty"]', function() {
                const subcountySelect = this;
                const scope = subcountySelect.closest('form') || document;
                const countySelect = scope.querySelector('[data-location="county"]');
                const wardSelect = scope.querySelector('[data-location="ward"]');
                fetchWards(countySelect?.dataset.wardsUrl, subcountySelect, wardSelect, '');
            });

            $(document).on('select2:select', '[data-location="county"]', function() {
                const countySelect = this;
                const scope = countySelect.closest('form') || document;
                const subcountySelect = scope.querySelector('[data-location="subcounty"]');
                const wardSelect = scope.querySelector('[data-location="ward"]');
                fetchSubcounties(countySelect, subcountySelect, wardSelect);
            });

            $(document).on('select2:select', '[data-location="subcounty"]', function() {
                const subcountySelect = this;
                const scope = subcountySelect.closest('form') || document;
                const countySelect = scope.querySelector('[data-location="county"]');
                const wardSelect = scope.querySelector('[data-location="ward"]');
                fetchWards(countySelect?.dataset.wardsUrl, subcountySelect, wardSelect, '');
            });
        }
    })();
</script>
