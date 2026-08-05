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
