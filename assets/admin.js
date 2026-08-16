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

	const postPasswordToggle = document.getElementById( 'ftmzi-use-post-password' );
	const postPassword = document.getElementById( 'ftmzi-post-password' );
	const postPrivate = document.getElementById( 'ftmzi-post-private' );
	const postDate = document.getElementById( 'ftmzi-post-date' );
	const postDatePicker = document.getElementById( 'ftmzi-post-datetime-picker' );
	const postDateValue = document.getElementById( 'ftmzi-post-date-value' );
	const postDateToggle = document.querySelector( '[data-ftmzi-date-toggle]' );
	const postDatePopover = document.querySelector( '[data-ftmzi-date-popover]' );
	const postDateNow = document.querySelector( '[data-ftmzi-date-now]' );
	const postDateClear = document.querySelector( '[data-ftmzi-date-clear]' );
	const postDateClose = document.querySelector( '[data-ftmzi-date-close]' );

	if ( postDate && postDatePicker && postDateValue ) {
		const formatDatePart = function ( value ) {
			return String( value ).padStart( 2, '0' );
		};
		const setPopoverOpen = function ( isOpen ) {
			if ( ! postDatePopover ) {
				return;
			}

			postDatePopover.classList.toggle( 'is-open', isOpen );
			postDatePopover.setAttribute( 'aria-hidden', isOpen ? 'false' : 'true' );
			postDate.setAttribute( 'aria-expanded', isOpen ? 'true' : 'false' );
			if ( postDateToggle ) {
				postDateToggle.setAttribute( 'aria-expanded', isOpen ? 'true' : 'false' );
			}
			if ( isOpen ) {
				window.requestAnimationFrame( function () {
					const viewportWidth = document.documentElement.clientWidth;
					const triggerBounds = postDate.getBoundingClientRect();
					const popoverHeight = postDatePopover.offsetHeight;
					const popoverWidth = postDatePopover.offsetWidth;
					const opensAbove = triggerBounds.bottom + popoverHeight + 8 > window.innerHeight && triggerBounds.top - popoverHeight - 8 >= 8;
					const top = opensAbove ? triggerBounds.top - popoverHeight - 8 : triggerBounds.bottom + 8;
					const left = Math.min( Math.max( 8, triggerBounds.left ), Math.max( 8, viewportWidth - popoverWidth - 8 ) );

					postDatePopover.style.top = Math.max( 8, top ) + 'px';
					postDatePopover.style.left = left + 'px';
				} );
			} else {
				postDatePopover.style.removeProperty( 'top' );
				postDatePopover.style.removeProperty( 'left' );
			}
		};
		const applyPickerValue = function () {
			if ( ! postDatePicker.value ) {
				postDateValue.value = '';
				postDate.value = '';
				postDate.setCustomValidity( '' );
				return;
			}

			const selectedDateTime = postDatePicker.value;
			const time = selectedDateTime.slice( 11 ).length === 5 ? selectedDateTime + ':00' : selectedDateTime;

			postDateValue.value = time;
			postDate.value = time.replace( 'T', ' ' );
			postDate.setCustomValidity( '' );
		};
		const syncPickerFromInput = function () {
			const match = postDate.value.trim().match( /^(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2}(?::\d{2})?))?$/ );

			if ( ! match ) {
				postDateValue.value = '';
				if ( '' === postDate.value.trim() ) {
					postDatePicker.value = '';
					postDate.setCustomValidity( '' );
				} else {
					postDate.setCustomValidity( postDate.dataset.ftmziInvalidMessage || '' );
				}
				return;
			}

			const selectedTime = match[ 2 ] || '00:00:00';
			postDatePicker.value = match[ 1 ] + 'T' + ( selectedTime.length === 5 ? selectedTime + ':00' : selectedTime );
			applyPickerValue();
		};
		const setCurrentPostDate = function () {
			const now = new Date();

			postDatePicker.value = now.getFullYear() + '-' + formatDatePart( now.getMonth() + 1 ) + '-' + formatDatePart( now.getDate() ) + 'T' + formatDatePart( now.getHours() ) + ':' + formatDatePart( now.getMinutes() ) + ':' + formatDatePart( now.getSeconds() );
			applyPickerValue();
		};
		const clearPostDate = function () {
			postDate.value = '';
			postDatePicker.value = '';
			postDateValue.value = '';
			setPopoverOpen( false );
			if ( postDateToggle ) {
				postDateToggle.focus();
			}
		};

		postDate.addEventListener( 'change', syncPickerFromInput );
		postDate.addEventListener( 'focus', function () {
			syncPickerFromInput();
			setPopoverOpen( true );
		} );
		postDatePicker.addEventListener( 'change', applyPickerValue );
		if ( postDateToggle ) {
			postDateToggle.addEventListener( 'click', function () {
				if ( ! postDatePopover ) {
					return;
				}

				syncPickerFromInput();
				setPopoverOpen( ! postDatePopover.classList.contains( 'is-open' ) );
			} );
		}
		if ( postDateNow ) {
			postDateNow.addEventListener( 'click', setCurrentPostDate );
		}
		if ( postDateClear ) {
			postDateClear.addEventListener( 'click', clearPostDate );
		}
		if ( postDateClose ) {
			postDateClose.addEventListener( 'click', function () {
				applyPickerValue();
				setPopoverOpen( false );
				if ( postDateToggle ) {
					postDateToggle.focus();
				}
			} );
		}
		document.addEventListener( 'click', function ( event ) {
			if ( postDatePopover && postDatePopover.classList.contains( 'is-open' ) && ! event.target.closest( '[data-ftmzi-date-picker]' ) ) {
				setPopoverOpen( false );
			}
		} );
		window.addEventListener( 'resize', function () {
			if ( postDatePopover && postDatePopover.classList.contains( 'is-open' ) ) {
				setPopoverOpen( true );
			}
		} );
		document.addEventListener( 'scroll', function () {
			if ( postDatePopover && postDatePopover.classList.contains( 'is-open' ) ) {
				setPopoverOpen( true );
			}
		}, true );
		postDate.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				setPopoverOpen( false );
				postDate.blur();
			}
		} );
		syncPickerFromInput();
	}

	if ( postPasswordToggle && postPassword ) {
		const updatePostPassword = function () {
			const passwordAllowed = ! postPrivate || ! postPrivate.checked;

			postPasswordToggle.disabled = ! passwordAllowed;
			if ( ! passwordAllowed ) {
				postPasswordToggle.checked = false;
			}

			postPassword.disabled = ! passwordAllowed || ! postPasswordToggle.checked;
			postPassword.required = passwordAllowed && postPasswordToggle.checked;

			if ( postPassword.disabled ) {
				postPassword.value = '';
			}
		};

		postPasswordToggle.addEventListener( 'change', updatePostPassword );
		if ( postPrivate ) {
			postPrivate.addEventListener( 'change', updatePostPassword );
		}
		updatePostPassword();
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

	const importForm = document.querySelector( '[data-ftmzi-import-form]' );

	if ( importForm && window.ftmziAdmin ) {
		const fileInput = document.getElementById( 'ftmzi-markdown-zip' );
		const fileClearButton = document.querySelector( '[data-ftmzi-file-clear]' );
		const queue = document.querySelector( '[data-ftmzi-import-queue]' );
		const queueList = document.querySelector( '[data-ftmzi-import-list]' );
		const queueSummary = document.querySelector( '[data-ftmzi-import-summary]' );
		const queueCount = document.querySelector( '[data-ftmzi-import-count]' );
		const dashboardChart = document.querySelector( '[data-ftmzi-import-dashboard-chart]' );
		const totalMetric = document.querySelector( '[data-ftmzi-import-total]' );
		const successMetric = document.querySelector( '[data-ftmzi-import-success]' );
		const failedMetric = document.querySelector( '[data-ftmzi-import-failed]' );
		const invalidMetric = document.querySelector( '[data-ftmzi-import-invalid]' );
		const successPercent = document.querySelector( '[data-ftmzi-import-success-percent]' );
		const failedPercent = document.querySelector( '[data-ftmzi-import-failed-percent]' );
		const invalidPercent = document.querySelector( '[data-ftmzi-import-invalid-percent]' );
		const dashboardLegends = document.querySelectorAll( '[data-ftmzi-import-legend]' );
		const resetButton = document.querySelector( '[data-ftmzi-import-reset]' );
		const submitButton = importForm.querySelector( 'input[type="submit"], button[type="submit"]' );
		const strings = window.ftmziAdmin.strings || {};
		const persistedStats = window.ftmziAdmin.importStats || {};
		const importStats = {
			success: Number( persistedStats.success || 0 ),
			failed: Number( persistedStats.failed || 0 ),
			invalid: Number( persistedStats.invalid || 0 ),
		};

		const formatString = function ( template, values ) {
			let valueIndex = 0;

			return String( template || '' ).replace( /%(?:(\d+)\$)?[ds]/g, function ( match, position ) {
				const index = position ? Number( position ) - 1 : valueIndex++;
				return typeof values[ index ] === 'undefined' ? match : values[ index ];
			} );
		};

		const updateFileClearButton = function () {
			if ( fileClearButton ) {
				fileClearButton.hidden = ! fileInput || ! fileInput.files || ! fileInput.files.length;
			}
		};

		const setQueueSummary = function ( message ) {
			if ( queueSummary ) {
				queueSummary.textContent = message;
			}
		};

		const setQueueCount = function ( completed, total ) {
			if ( queueCount ) {
				queueCount.textContent = completed + ' / ' + total;
			}
		};

		const updateDashboard = function () {
			const total = importStats.success + importStats.failed + importStats.invalid;
			const updateMetric = function ( element, value ) {
				if ( element ) {
					element.textContent = value;
				}
			};
			updateMetric( successMetric, importStats.success );
			updateMetric( failedMetric, importStats.failed );
			updateMetric( invalidMetric, importStats.invalid );
			updateMetric( totalMetric, total );
			updateMetric( successPercent, total ? Math.round( importStats.success / total * 100 ) + '%' : '0%' );
			updateMetric( failedPercent, total ? Math.round( importStats.failed / total * 100 ) + '%' : '0%' );
			updateMetric( invalidPercent, total ? Math.round( importStats.invalid / total * 100 ) + '%' : '0%' );

			if ( dashboardChart ) {
				const successEnd = total ? importStats.success / total * 360 : 0;
				const failedEnd = successEnd + ( total ? importStats.failed / total * 360 : 0 );

				dashboardChart.style.setProperty( '--ftmzi-success-end', successEnd + 'deg' );
				dashboardChart.style.setProperty( '--ftmzi-failed-end', failedEnd + 'deg' );
				dashboardChart.classList.toggle( 'is-empty', ! total );
				dashboardChart.setAttribute( 'aria-valuenow', total );
				dashboardChart.setAttribute( 'aria-valuemax', total || 1 );
			}
		};

		const resetQueueState = function () {
			setQueueCount( 0, 0 );
			setQueueSummary( strings.waiting || '' );

			if ( queueList ) {
				queueList.innerHTML = '';
			}
		};

		const addQueueItem = function ( file ) {
			const item = document.createElement( 'li' );
			const name = document.createElement( 'span' );
			const status = document.createElement( 'span' );
			const progress = document.createElement( 'span' );
			const progressValue = document.createElement( 'span' );

			item.className = 'ftmzi-import-queue__item is-pending';
			name.className = 'ftmzi-import-queue__name';
			status.className = 'ftmzi-import-queue__status';
			progress.className = 'ftmzi-import-queue__progress';
			name.textContent = file.name;
			status.textContent = strings.pending || '...';
			progress.appendChild( progressValue );
			item.appendChild( name );
			item.appendChild( status );
			item.appendChild( progress );

			if ( queueList ) {
				queueList.appendChild( item );
			}

			return {
				item: item,
				status: status,
				progress: progressValue,
			};
		};

		const updateQueueItem = function ( queueItem, state, message, progress ) {
			queueItem.item.className = 'ftmzi-import-queue__item is-' + state;
			queueItem.status.textContent = message;
			queueItem.progress.style.setProperty( '--ftmzi-task-progress', progress + '%' );
		};

		const setFormDisabled = function ( disabled ) {
			const controls = importForm.querySelectorAll( 'input, select, button, textarea' );

			controls.forEach( function ( control ) {
				if ( disabled ) {
					control.dataset.ftmziWasDisabled = control.disabled ? '1' : '0';
					control.disabled = true;
				} else {
					control.disabled = '1' === control.dataset.ftmziWasDisabled;
					delete control.dataset.ftmziWasDisabled;
				}
			} );
		};

		const importQueuedFile = async function ( file, baseFormData ) {
			const formData = new FormData();

			baseFormData.forEach( function ( value, key ) {
				if ( 'action' !== key && fileInput.name !== key ) {
					formData.append( key, value );
				}
			} );

			formData.append( 'action', 'ftmzi_import_file' );
			formData.append( 'markdown_zip', file, file.name );

			const response = await window.fetch( window.ftmziAdmin.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: formData,
			} );
			const payload = await response.json();

			if ( ! response.ok || ! payload.success ) {
				throw new Error( payload && payload.data && payload.data.message ? payload.data.message : strings.networkFailed );
			}

			return payload.data;
		};

		if ( resetButton ) {
			resetButton.addEventListener( 'click', function () {
				window.location.reload();
			} );
		}

		const clearImportLogForm = document.querySelector( '[data-ftmzi-clear-import-log-form]' );
		if ( clearImportLogForm ) {
			clearImportLogForm.addEventListener( 'submit', function ( event ) {
				if ( ! window.confirm( strings.clearLogsConfirmation || '' ) ) {
					event.preventDefault();
				}
			} );
		}

		if ( fileInput && fileClearButton ) {
			fileInput.addEventListener( 'change', updateFileClearButton );
			fileClearButton.addEventListener( 'click', function () {
				fileInput.value = '';
				updateFileClearButton();
				fileInput.focus();
			} );
			updateFileClearButton();
		}

		if ( dashboardChart && dashboardLegends.length ) {
			const dashboardChartStage = dashboardChart.closest( '.ftmzi-import-dashboard__chart-stage' );
			const setDashboardHighlight = function ( type ) {
				dashboardChart.classList.remove( 'is-highlight-success', 'is-highlight-failed', 'is-highlight-invalid' );
				if ( dashboardChartStage ) {
					dashboardChartStage.classList.remove( 'is-highlight-success', 'is-highlight-failed', 'is-highlight-invalid' );
				}
				if ( type ) {
					dashboardChart.classList.add( 'is-highlight-' + type );
					if ( dashboardChartStage ) {
						dashboardChartStage.classList.add( 'is-highlight-' + type );
					}
				}
			};

			dashboardLegends.forEach( function ( legend ) {
				const type = legend.dataset.ftmziImportLegend;

				legend.addEventListener( 'mouseenter', function () {
					setDashboardHighlight( type );
				} );
				legend.addEventListener( 'mouseleave', function () {
					setDashboardHighlight();
				} );
				legend.addEventListener( 'focus', function () {
					setDashboardHighlight( type );
				} );
				legend.addEventListener( 'blur', function () {
					setDashboardHighlight();
				} );
			} );
		}

		updateDashboard();

		importForm.addEventListener( 'submit', async function ( event ) {
			const files = fileInput && fileInput.files ? Array.prototype.slice.call( fileInput.files ) : [];

			if ( files.length < 2 ) {
				return;
			}

			event.preventDefault();

			if ( ! queue || ! queueList ) {
				return;
			}

			resetQueueState();
			const baseFormData = new FormData( importForm );
			setFormDisabled( true );
			if ( resetButton ) {
				resetButton.disabled = true;
			}

			if ( submitButton ) {
				submitButton.value = strings.buttonLoading || submitButton.value;
				if ( 'BUTTON' === submitButton.tagName ) {
					submitButton.textContent = strings.buttonLoading || submitButton.textContent;
				}
			}

			const items = files.map( addQueueItem );
			const result = {
				created: 0,
				failed: 0,
				skipped: 0,
			};

			setQueueCount( 0, files.length );
			setQueueSummary( formatString( strings.preparing, [ files.length ] ) );

			for ( let index = 0; index < files.length; index++ ) {
				const file = files[ index ];
				const queueItem = items[ index ];

				updateQueueItem( queueItem, 'processing', strings.processing || formatString( strings.importing, [ file.name ] ), 15 );
				setQueueSummary( formatString( strings.importing, [ file.name ] ) );

				try {
					const data = await importQueuedFile( file, baseFormData );

					if ( 'skipped' === data.status ) {
						result.skipped++;
						importStats.invalid++;
						updateQueueItem( queueItem, 'skipped', strings.skipped, 100 );
					} else if ( 'failed' === data.status ) {
						result.failed++;
						importStats.failed++;
						updateQueueItem( queueItem, 'failed', formatString( strings.failed, [ data.message || strings.networkFailed ] ), 100 );
					} else {
						result.created += Number( data.created || 0 );
						importStats.success += Number( data.created || 0 );

						if ( 'partial' === data.status ) {
							result.failed++;
							importStats.failed += Number( data.failed || 1 );
							updateQueueItem( queueItem, 'partial', formatString( strings.partial, [ data.created || 0, data.failed || 0 ] ), 100 );
						} else {
							updateQueueItem( queueItem, 'success', formatString( strings.success, [ data.created || 0 ] ), 100 );
						}
					}
				} catch ( error ) {
					result.failed++;
					importStats.failed++;
					updateQueueItem( queueItem, 'failed', formatString( strings.failed, [ error.message || strings.networkFailed ] ), 100 );
				}

				updateDashboard();
				setQueueCount( index + 1, files.length );
			}

			setQueueSummary( formatString( strings.completed, [ result.created, result.failed, result.skipped ] ) );
			if ( fileInput ) {
				fileInput.value = '';
				updateFileClearButton();
			}
			setFormDisabled( false );
			if ( resetButton ) {
				resetButton.disabled = false;
			}

			if ( submitButton ) {
				submitButton.value = strings.buttonDefault || submitButton.value;
				if ( 'BUTTON' === submitButton.tagName ) {
					submitButton.textContent = strings.buttonDefault || submitButton.textContent;
				}
			}
		} );
	}
}() );
