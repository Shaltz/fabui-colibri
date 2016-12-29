<?php
/**
 * 
 * @author Krios Mane
 * @version 0.1
 * @license https://opensource.org/licenses/GPL-3.0
 * 
 */
?>
<script type="text/javascript">
	$(document).ready(function() {
		<?php if($runningTask): ?>
		resumeTask();
		<?php else: ?>
		checkBundleStatus();
		<?php endif; ?>
	});
	/**
	* ============================================================
	**/
	/**
	*
	**/
	function checkBundleStatus()
	{
		$(".status").html('<i class="fa fa-spinner fa-spin"></i> checking for updates');
		$.ajax({
			type: "POST",
			url: "<?php echo site_url('updates/bundleStatus') ?>",
			dataType: 'json'
		}).done(function(response) {
			if(response.remote_connection == false){
				noInternetAvailable();
			}else{
				handleAvailableUpdates(response);
			}
		});
	}
	/**
	* 
	**/
	function noInternetAvailable()
	{
	}
	/**
	*
	**/
	function handleAvailableUpdates(object) {

		$('.fabtotum-icon').parent().addClass('tada animated');
		
		if(object.update.available){
			$(".status").html('<i class="fa fa-exclamation-circle"></i> New important software updates are now available');
			$('.fabtotum-icon .badge').find('i').removeClass('fa-spin fa-refresh').addClass('fa-exclamation-circle');
			
			var buttons = '';
			buttons += '<button class="btn btn-default  action-buttons" id="do-update"><i class="fa fa-refresh"></i> Update</button> ';
			buttons += '<button class="btn btn-default  action-buttons" id="bundle-details"><i class="fa fa-reorder"></i> View details</button> ';

			$(".button-container").html(buttons);
			crateBundlesTable(object);

			$("#bundle-details").on('click', showHideBundlesDetails);
			$("#do-update").on('click', doUpdate);

		}else{
			$(".status").html('Great! Your FABtotum Personal Fabricator is up to date');
		}
	}


	function crateBundlesTable(data)
	{
		var html = '<table id="bundles-table" class="table  table-forum">' + 
		 				'<thead>' +
							'<tr>' +
								'<th colspan="2">Bundle</th>' +
								/*'<th class="text-center" style="width:150px">Installed version</th>' + */
								'<th class="text-center" style="width:150px">Remote version</th>' +
								'<th class="text-center" style="width:40px"><div class="checkbox" style="margin-top:0px; margin-bottom:0px"><label><input id="select-all-bundles" value="all_bundles" type="checkbox" class="checkbox"><span></span></label></div></th>' + 
							'</tr>' + 
						'</thead>' + 
						'<tbody>';

		$.each(data.bundles, function(bundle_name, object) {
			if(object.need_update){
				var tr_class = 'warning';
				var icon = 'fa fa-exclamation-circle text-muted';
				var checked = 'checked="checked"';

				html += '<tr id="tr-' + bundle_name + '" class="' + tr_class + '">' +
		        	'<td  class="text-center" style="width:40px;"><i id="icon-'+ bundle_name +'" class="'+ icon + '"></i></td>' +
		        	'<td><h4><a href="javascript:void(0)">' + bundle_name.capitalize() + '</a> <small></small>' + 
		        	'<small id="small-'+ bundle_name +'">Installed version: ' + object.local +' | Build date: ' + object.info.build_date + '</small>' +
		        	'</h4></td>' + 
		        	/*'<td class="text-center">' + object.local + '</td>'+*/
		        	'<td class="text-center">' + object.latest + ' </td>' +
		        	'<td class="text-center" style="width:40px"><div class="checkbox" style="margin-top:0px;"><label><input value="'+bundle_name +'" type="checkbox" '+checked +' class="checkbox"><span></span></label></div></td>' + 
		        '</tr>';
			}
		});

		$.each(data.bundles, function(bundle_name, object) {
			if(!object.need_update){
				var tr_class = '';
				var icon = 'fa fa-check text-muted';
				var checked = '';

				html += '<tr id="tr-' + bundle_name + '" class="' + tr_class + '">' +
		        	'<td  class="text-center" style="width:40px;"><i id="icon-'+ bundle_name +'" class="'+ icon + '"></i></td>' +
		        	'<td><h4><a href="javascript:void(0)">' + bundle_name.capitalize() + '</a> <small></small>' + 
		        	'<small id="small-'+ bundle_name +'">Installed version: ' + object.local +' | Build date: ' + object.info.build_date + '</small>' +
		        	'</h4></td>' + 
		        	/*'<td class="text-center">' + object.local + '</td>'+*/
		        	'<td class="text-center">' + object.latest + ' </td>' +
		        	'<td class="text-center" style="width:40px"><div class="checkbox" style="margin-top:0px;"><label><input value="'+bundle_name +'" type="checkbox" '+checked +' class="checkbox"><span></span></label></div></td>' + 
		        '</tr>';
			}
		});
		
		html +=    		'<tbdoy>' + 
					'</table>';
		if(data.update.number > 0) {
			$("#bundles-badge").html(data.update.number).addClass('animated fadeIn');
		}
		$("#bundles_tab").html(html);
		$("#select-all-bundles").on('click', function(){
			var that = this;
			$(this).closest("table").find("tr > td input:checkbox").each(function() {
				this.checked = that.checked;
			});
		});
	}
	/**
	*
	**/
	function showHideBundlesDetails()
	{
		var button = $(this);
		
		if($('.fabtotum-icon').is(":visible")){
			
			$(".fabtotum-icon").slideUp(function(){
				$(".tabs-container").slideDown(function(){
					button.html("<i class='fa fa-reorder'></i> Hide details");
				});
			});
		}else{
			$(".tabs-container").slideUp(function(){
				$(".fabtotum-icon").slideDown(function(){
					$(".fabtotum-icon").css( "display", "inline" );
					button.html("<i class='fa fa-reorder'></i> View details");
				});
				
			});
		}
	}
	/**
	*
	**/
	function doUpdate()
	{
		disableButton('.action-buttons');
		var bundles_to_update = [];
		$("#bundles-table").find("tr > td input:checkbox").each(function () {
			if($(this).is(':checked')){
				bundles_to_update.push($(this).val());
			}
		});
		startUpdate(bundles_to_update);
	}
	/**
	*
	**/
	function startUpdate(bundles)
	{
		$.ajax({
			type: "POST",
			data: {'bundles': bundles},
			url: "<?php echo site_url('updates/startUpdate') ?>",
			dataType: 'json'
		}).done(function(response) {
			initTask();
		});
	}
	/*
	*
	**/
	function showHideUpdateDetails()
	{
		var button = $(this);

		if($(".update-details").is(":visible")){
			$(".update-details").slideUp(function(){
				button.html("<i class='fa fa-reorder'></i> View details");
			});
		}else{
			$(".update-details").slideDown(function(){
				button.html("<i class='fa fa-reorder'></i> Hide details");
			});
		}
	}
	/**
	*
	**/
	if(typeof manageMonitor != 'function'){
		window.manageMonitor = function(data){
			handleTask(data);
		}
	}
	/**
	*
	**/
	function handleTask(data)
	{
		var task    = data.task;
		var bundles = data.update.bundles;
		var current = data.update.current;
	
		handleTaskStatus(task.status);
		handleCurrent(data.update.current);
		handleUpdate(data.update);

	}
	/**
	*
	**/
	function handleTaskStatus(status)
	{
		switch(status){ 
			case 'preparing':
				$(".status").html('Connecting to update server...');
				break;
			case 'runnning':
				break;
			case 'completed':
				$(".status").html('<i class="fa fa-check"></i> Update completed');
				$('.fabtotum-icon .badge').addClass('check').find('i').removeClass('fa-spin fa-refresh').addClass('fa-check');
				$("#do-abort").remove();
				fabApp.unFreezeMenu();
				$(".small").html('A reboot is needed to apply new features');
				if($("#do-reboot").length == 0) $(".button-container").append('<button class="btn btn-default  action-buttons" id="do-reboot"> Reboot now</button>')
				$('.fabtotum-icon').parent().removeClass().addClass('tada animated');
				$("#do-reboot").on('click', fabApp.reboot);
				break;
		}
	}
	/**
	*
	**/
	function handleCurrent(current)
	{
		if(current.status != ''){
			switch(current.status){
				case 'downloading' :
					$(".status").html('<i class="fa fa-download"></i> Downloading bundle (' + current.bundle.capitalize() +')');
					break;
				case 'installing' :
					$(".status").html('<i class="fa fa-gear fa-spin"></i> Installing bundle  (' + current.bundle.capitalize() +')');
					break;
			}
		}
	}
	/**
	*
	**/
	function handleUpdate(object)
	{
		var table = '<table class="table  table-forum"><thead><tr></tr></thead><tbody>';
		
		$.each(object.bundles, function(bundle_name, bundle) {
			
			var tr_class = bundle.status == 'error' ? 'warning' : '';
			
			table += '<tr class="'+ tr_class +'">';
			table += '<td width="20" class="text-center"></td>';
			table += '<td><h4><a href="javascript:void(0);">' + bundle_name.capitalize() + '</a>';
			
			switch(bundle.status){
				case 'downloading':
					label = '<p><i class="fa fa-download"></i> Downloading (' + humanFileSize(bundle.files.bundle.size)  + ') <span class="pull-right">'+ parseInt(bundle.files.bundle.progress)  +'%</span></p>'+
						'<div class="progress progress-xs"> '+
							'<div class="progress-bar bg-color-blue" style="width: '+ parseInt(bundle.files.bundle.progress) +'%;"></div> '+
						'</div>';
					break;
				case 'downloaded':
					label = '<i class="fa fa-check"></i> Downloaded';
					break;
				case 'installing':
					label = '<i class="fa fa-gear fa-spin"></i> Installing ';
					break;
				case 'installed':
					label = '<i class="fa fa-check"></i> Installed';
					break;
				case 'error':
					label = '<p><i class="fa fa-times"></i> Error</p>' +
							'<p>' + bundle.message.replaceAll('\n', '<br>') + '</p>';
					console.log(label);
					break;
				default:
					label = bundle.status;
					break;
			}
			table += '<small>' + label + '</small>';
			table += '</h4></td>';
			table += '</tr>';
		});
		
		table += '</tbody></table>';
		
		$(".update-details").html(table);
	}
	/**
	*
	**/
	function resumeTask()
	{
		initTask();
	}
	/**
	*
	**/
	function initTask()
	{
		fabApp.freezeMenu('updates');
		$(".small").html('Please don\'t turn off the printer until the operation is completed');
		
		$(".tabs-container").slideUp(function() {
			$(".fabtotum-icon").slideDown(function(){
				$(".fabtotum-icon").css( "display", "inline" );
				$('.fabtotum-icon .badge').find('i').removeClass('fa-exclamation-circle').addClass('fa-spin fa-refresh');

				var buttons = '';
				buttons += '<button class="btn btn-default  action-buttons" id="do-abort"><i class="fa fa-times"></i> Abort</button> ';
				buttons += '<button class="btn btn-default  action-buttons" id="update-details"><i class="fa fa-reorder"></i> View details</button> ';

				$(".button-container").html(buttons);
				$("#update-details").on('click', showHideUpdateDetails);
				
			});
		});
	}
</script>