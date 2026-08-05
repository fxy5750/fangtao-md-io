( function () {
	'use strict';

	const importPostType = document.getElementById( 'ftmzi-post-type' );
	const importCategory = document.querySelector( '[data-ftmzi-category-field]' );

	if ( importPostType && importCategory ) {
		const categorySelect = importCategory.querySelector( 'select' );
		const updateImportCategory = function () {
			const isPost = 'post' === importPostType.value;

			importCategory.hidden = ! isPost;
			if ( categorySelect ) {
				categorySelect.disabled = ! isPost;
			}
		};

		importPostType.addEventListener( 'change', updateImportCategory );
		updateImportCategory();
	}

	document.querySelectorAll( '[data-ftmzi-parser-select]' ).forEach( function ( select ) {
		const flavorTarget = document.getElementById( select.dataset.flavorTarget );

		if ( ! flavorTarget ) {
			return;
		}

		const updateParserFlavor = function () {
			const option = select.options[ select.selectedIndex ];

			flavorTarget.textContent = option ? option.dataset.flavor || '' : '';
		};

		select.addEventListener( 'change', updateParserFlavor );
		updateParserFlavor();
	} );

	const exportPostType = document.getElementById( 'ftmzi-export-post-type' );
	const exportPostFilters = document.querySelectorAll( '[data-ftmzi-export-post-filter]' );

	if ( exportPostType && exportPostFilters.length ) {
		const updateExportFilters = function () {
			const isPost = 'post' === exportPostType.value;

			exportPostFilters.forEach( function ( field ) {
				field.hidden = ! isPost;
				field.querySelectorAll( 'select' ).forEach( function ( select ) {
					select.disabled = ! isPost;
				} );
			} );
		};

		exportPostType.addEventListener( 'change', updateExportFilters );
		updateExportFilters();
	}
}() );
