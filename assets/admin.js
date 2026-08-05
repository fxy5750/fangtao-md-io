( function () {
	'use strict';

	const postTypeSelect = document.getElementById( 'ftmzi-post-type' );
	const categoryField = document.querySelector( '[data-ftmzi-category-field]' );

	if ( ! postTypeSelect || ! categoryField ) {
		return;
	}

	const categorySelect = categoryField.querySelector( 'select' );
	const updateCategoryField = function () {
		const isPost = 'post' === postTypeSelect.value;

		categoryField.hidden = ! isPost;

		if ( categorySelect ) {
			categorySelect.disabled = ! isPost;
		}
	};

	postTypeSelect.addEventListener( 'change', updateCategoryField );
	updateCategoryField();
}() );
