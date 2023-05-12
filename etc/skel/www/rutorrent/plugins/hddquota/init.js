/******************
* Pulsed Media HDD Quota ruTorrent Plugin
*
* This plugin shows a gauge based on available quota. This is based on Novik's original
*
* Author: Aleksi / NuCode
* License: GPL
****************/
plugin.loadMainCSS()

plugin.setValue = function( full, free, hardLimit ) {
    //alert('Data is, F:' + full + ' FREE:' + free + ' HARD: ' + hardLimit);
    var percent = iv(full ? (full-free)/full*100 : 0);
    
    var humanReadableFree = '';
    //if (free < 0) humanReadableFree = "-" + theConverter.bytes( 0 - free );
    //    else humanReadableFree = theConverter.bytes(free)
    
    humanReadableFree = theConverter.bytes( (full-free) ); // Quick'n'dirty convert to used instead of free remaining
    
    var gaugeBackgroundColor = (new RGBackground()).setGradient(this.prgStartColor,this.prgEndColor, percent).getColor();
    if (percent > 100) gaugeBackgroundColor = '#FF4040';
    
	$("#meter-disk-value").width( (percent <= 100) ? percent+"%" : "100%" ).css( { 
        "background-color": gaugeBackgroundColor,
		visibility: !percent ? "hidden" : "visible"
    } );
    
    $("#meter-disk-td").attr("title", humanReadableFree + "/" + theConverter.bytes(full) + " Burst limit: " + theConverter.bytes(hardLimit));
    
	$("#meter-disk-text").text(percent+'%').css({
        "font-weight": (percent > 100) ? "bold" : "normal"
    });
}

plugin.init = function() {
	if(getCSSRule("#meter-disk-holder")) {
        
		plugin.prgStartColor = new RGBackground("#99E699");
		plugin.prgEndColor = new RGBackground("#EE9999");
		plugin.addPaneToStatusbar( "meter-disk-td", $("<div>").attr("id","meter-disk-holder").
			append( $("<span></span>").attr("id","meter-disk-text").css({overflow: "visible"}) ).
			append( $("<div>").attr("id","meter-disk-value").css({ visibility: "hidden", float: "left" }).width(0).html("&nbsp;") ).get(0) );

		plugin.check = function()
		{
			var AjaxReq = jQuery.ajax(
			{
				type: "GET",
				timeout: theWebUI.settings["webui.reqtimeout"],
			        async : true,
			        cache: false,
				url : "plugins/hddquota/action.php",
				dataType : "json",
				cache: false,
				success : function(data) {
                    //alert('Data acquired ' + data );
					plugin.setValue( data.total, data.free, data.hardLimit );
                    
				}
			});
		};
		plugin.check();        
		plugin.reqId = theRequestManager.addRequest( "ttl", null, plugin.check );
        
		plugin.markLoaded();
        
	}
	else
		window.setTimeout(arguments.callee,500);
};

plugin.onRemove = function()
{
	plugin.removePaneFromStatusbar("meter-disk-td");
	theRequestManager.removeRequest( "ttl", plugin.reqId );
}

plugin.init();