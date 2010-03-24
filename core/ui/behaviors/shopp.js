//
// Utility functions
//

/**
 * copyOf ()
 * Returns a copy/clone of an object
 **/
function copyOf (src) {
	var target = new Object();
	for (v in src) target[v] = src[v];
	return target;
}

/**
 * Array.indexOf ()
 * Provides indexOf method for browsers that
 * that don't implement JavaScript 1.6 (IE for example)
 **/
if (!Array.indexOf) {
	Array.prototype.indexOf = function(obj) {
		for (var i = 0; i < this.length; i++)
			if (this[i] == obj) return i;
		return -1;
	}
}

function getCurrencyFormat () {
	if (!ShoppSettings) return false;
	return {
		"cp":ShoppSettings.cpos,
		"c":ShoppSettings.currency,
		"p":parseInt(ShoppSettings.precision),
		"d":ShoppSettings.decimals,
		"t":ShoppSettings.thousands
	}
	
}

/**
 * asMoney ()
 * Add notation to an integer to display it as money.
 **/
function asMoney (number,format) {
	var currencyFormat = getCurrencyFormat();
	if (currencyFormat && !format) format = copyOf(currencyFormat);
	if (!format || !format['currency']) {
		format = {
			"cpos":true,
			"currency":"$",
			"precision":2,
			"decimals":".",
			"thousands":","
		}
	}
	
	number = formatNumber(number,format);
	if (format['cpos']) return format['currency']+number;
	return number+format['currency'];
}

/**
 * asPercent ()
 * Add notation to an integer to display it as a percentage.
 **/
function asPercent (number,format) {
	var currencyFormat = getCurrencyFormat();
	if (currencyFormat && !format) format = copyOf(currencyFormat);
	if (!format) {
		format = {
			"decimals":".",
			"thousands":","
		}
	}
	format['precision'] = 1;
	return formatNumber(number,format)+"%";
}

/**
 * formatNumber ()
 * Formats a number to denote thousands with decimal precision.
 **/
function formatNumber (number,format) {
	if (!format) {
		format = {
			"precision":2,
			"decimals":".",
			"thousands":","
		}
	}

	number = asNumber(number);
	var d = number.toFixed(format['precision']).toString().split(".");
	var number = "";
	if (format['indian']) {
		var digits = d[0].slice(0,-3);
		number = d[0].slice(-3,d[0].length) + ((number.length > 0)?format['thousands'] + number:number);
		for (var i = 0; i < (digits.length / 2); i++) 
			number = digits.slice(-2*(i+1),digits.length+(-2 * i)) + ((number.length > 0)?format['thousands'] + number:number);
	} else {
		for (var i = 0; i < (d[0].length / 3); i++) 
			number = d[0].slice(-3*(i+1),d[0].length+(-3 * i)) + ((number.length > 0)?format['thousands'] + number:number);
	}

	if (format['precision'] > 0) number += format['decimals'] + d[1];
	return number;

}

/**
 * asNumber ()
 * Convert a field with numeric and non-numeric characters
 * to a true integer for calculations.
 **/
var asNumber = function(number,format) {
	if (!number) return 0;
	var currencyFormat = getCurrencyFormat();
	if (currencyFormat && !format) format = copyOf(currencyFormat);
	if (!format || !format['currency']) {
		format = {
			"cpos":true,
			"currency":"$",
			"precision":2,
			"decimals":".",
			"thousands":","
		}
	}
	
	if (number instanceof Number) return new Number(number.toFixed(format['precision']));

	number = number.toString().replace(new RegExp(/[^\d\.\,]/g),""); // Reove any non-numeric string data
	number = number.toString().replace(new RegExp('\\'+format['thousands'],'g'),""); // Remove thousands

	if (format['precision'] > 0)
		number = number.toString().replace(new RegExp('\\'+format['decimals'],'g'),"."); // Convert decimal delimter
		
	if (isNaN(new Number(number)))
		number = number.replace(new RegExp(/\./g),"").replace(new RegExp(/\,/),"\.");

	return new Number(number);
}

/**
 * CallbackRegistry ()
 * Utility class to build a list of functions (callbacks) 
 * to be executed as needed
 **/
var CallbackRegistry = function() {
	this.callbacks = new Array();

	this.register = function (name,callback) {
		this.callbacks[name] = callback;
	}

	this.call = function(name,arg1,arg2,arg3) {
		this.callbacks[name](arg1,arg2,arg3);
	}
	
	this.get = function(name) {
		return this.callbacks[name];
	}
}

/**
 * formatFields ()
 * Find fields that need display formatting and 
 * run the approriate formatting.
 */
function formatFields () {
	(function($) {
		var f = $('input');
		f.each(function (i,e) {
			var e = $(e);
			if (e.hasClass('currency')) {
				e.change(function() {
					$(e).val(asMoney($(e).val()));
				}).change();
			}
		});
	})(jQuery)
}

if (!Number.prototype.roundFixed) {
	Number.prototype.roundFixed = function(precision) {
		var power = Math.pow(10, precision || 0);
		return String(Math.round(this * power)/power);
	}
}

//
// Catalog Behaviors
//
var ProductOptionsMenus;
(function($) {
	ProductOptionsMenus = function (target,hideDisabled,pricing,taxrate) {
		var _self = this;
		var i = 0;
		var previous = false;
		var current = false;
		var menucache = new Array();
		var menus = $(target);
		if (!taxrate) taxrate = 0;

		menus.each(function (id,menu) {
			current = menu;
			menucache[id] = $(menu).children();			
			if ($.browser.msie) disabledHandler(menu);
			if (id > 0)	previous = menus[id-1];
			if (menus.length == 1) {
				optionPriceTags();
			} else if (previous) {
				$(previous).change(function () {
					if (menus.index(current) == menus.length-1) optionPriceTags();
					if (this.selectedIndex == 0 && 
						this.options[0].value == "") $(menu).attr('disabled',true);
					else $(menu).removeAttr('disabled');
				}).change();
			}
			i++;
		});
			
		// Last menu needs pricing
		function optionPriceTags () {
			// Grab selections
			var selected = new Array();
			menus.not(current).each(function () {
				if ($(this).val() != "") selected.push($(this).val());
			});
			var currentSelection = $(current).val();
			$(current).empty();
			menucache[menus.index(current)].each(function (id,option) {
				$(option).appendTo($(current));
			});
			$(current).val(currentSelection);
			var keys = new Array();
			$(current).children('option').each(function () {
				if ($(this).val() != "") {
					var keys = selected.slice();
					keys.push($(this).val());
					var price = pricing[xorkey(keys)];
					if (!price) price = pricing[xorkey_deprecated(keys)];
					if (price) {
						var p = new Number(price.onsale?price.promoprice:price.price);
						var tax = new Number(p*taxrate);
						var pricetag = asMoney(new Number(p+tax));
						var optiontext = $(this).attr('text');
						var previoustag = optiontext.lastIndexOf("(");
						if (previoustag != -1) optiontext = optiontext.substr(0,previoustag);
						$(this).attr('text',optiontext+"  ("+pricetag+")");
						if ((price.inventory == "on" && price.stock == 0) || price.type == "N/A") {
							if ($(this).attr('selected')) 
								$(this).parent().attr('selectedIndex',0);
							if (hideDisabled) $(this).remove();
							else optionDisable(this);
						
						} else $(this).removeAttr('disabled').show();
						if (price.type == "N/A" && hideDisabled) $(this).remove();
					}
				}
			});
		}
	
		// Magic key generator
		function xorkey (ids) {
			for (var key=0,i=0; i < ids.length; i++) 
				key = key ^ (ids[i]*7001);
			return key;
		}

		function xorkey_deprecated (ids) {
			for (var key=0,i=0; i < ids.length; i++) 
				key = key ^ (ids[i]*101);
			return key;
		}
		
		function optionDisable (option) {
			$(option).attr('disabled',true);
			if (!$.browser.msie) return;
			$(option).css('color','#ccc');
		}
		
		function disabledHandler (menu) {
			$(menu).change(function () {
				if (!this.options[this.selectedIndex].disabled) {
					this.lastSelected = this.selectedIndex;
					return true;
				}
				if (this.lastSelected) this.selectedIndex = this.lastSelected;
				else {
					var firstEnabled = $(this).children('option:not(:disabled)').get(0);
					this.selectedIndex = firstEnabled?firstEnabled.index:0;
				}				
			});
		}		
		
	}
})(jQuery)


//
// Cart Behaviors
//

/**
 * addtocart ()
 * Makes a request to add the selected product/product variation
 * to the shopper's cart
 **/
function addtocart (form) {
	(function($) {
	
	var options = $(form).find('select.options');
	if (options && options_default) {
		var selections = true;
		for (menu in options) 
			if (options[menu].selectedIndex == 0 && options[menu][0].value == "") selections = false;

		if (!selections) {
			if (!options_required) options_required = "You must select the options for this item before you can add it to your shopping cart.";
			alert(options_required);
			return false;
		}
	}

	if ($(form).find('input.addtocart').hasClass('ajax')) 
		ShoppCartAjaxRequest(form.action,$(form).serialize());
	else form.submit();

	})(jQuery)
	return false;
}

/**
 * cartajax ()
 * Makes an asyncronous request to the cart
 **/
function cartajax (url,data,response) {
	(function($) {
	if (!response) response = "json";
	var datatype = ((response == 'json')?'json':'string');
	$.ajax({
		type:"POST",
		url:url,
		data:data+"&response="+response,
		timeout:10000,
		dataType:datatype,
		success:function (cart) {
			ShoppCartAjaxHandler(cart);
		},
		error:function () { }
	});
	})(jQuery)
}

/**
 * ShoppCartAjaxRequest ()
 * Overridable wrapper function to call cartajax.
 * Developers can recreate this function in their own
 * custom JS libraries to change the way cartajax is called.
 **/
var ShoppCartAjaxRequest = function (url,data,response) {
	cartajax(url,data,response);
}

/**
 * ShoppCartAjaxHandler ()
 * Overridable wrapper function to handle cartajax responses.
 * Developers can recreate this function in their own
 * custom JS libraries to change the way the cart response
 * is processed and displayed to the shopper.
 **/
var ShoppCartAjaxHandler = function (cart) {
	(function($) {
		var display = $('#shopp-cart-ajax');
		display.empty().hide(); // clear any previous additions
		var item = $('<ul></ul>').appendTo(display);
		if (cart.Item.thumbnail)
			$('<li><img src="'+cart.Item.thumbnail.uri+'" alt="" width="'+cart.Item.thumbnail.width+'"  height="'+cart.Item.thumbnail.height+'" /></li>').appendTo(item);
		$('<li></li>').html('<strong>'+cart.Item.name+'</strong>').appendTo(item);
		if (cart.Item.optionlabel.length > 0)
			$('<li></li>').html(cart.Item.optionlabel).appendTo(item);
		$('<li></li>').html(asMoney(cart.Item.unitprice)).appendTo(item);
		
		if ($('#shopp-sidecart-items').length > 0) {
			$('#shopp-sidecart-items').html(cart.Totals.quantity);
			$('#shopp-sidecart-total').html(asMoney(cart.Totals.total));			
		} else {
			$('.widget_shoppcartwidget p.status').html('<a href="'+cart.url+'"><span id="shopp-sidecart-items">'+cart.Totals.quantity+'</span> <strong>Items</strong> &mdash; <strong>Total</strong> <span id="shopp-sidecart-total">'+asMoney(cart.Totals.total)+'</span></a>');
		}
		display.slideDown();
	})(jQuery)	
}


//
// Generic behaviors
//

/**
 * quickSelects ()
 * Usability behavior to add automatic select-all to a field 
 * when activating the field by mouse click
 **/
function quickSelects (target) {
	jQuery('input.selectall').mouseup(function () { this.select(); });
}

/**
 * buttonHandlers ()
 * Hooks callbacks to button events
 **/
function buttonHandlers () {
	(function($) {
		$('input.addtocart').each(function() {
			var form = $(this).parents('form.product');
			if (!form) return false;
			$(form).submit(function (e) {
				e.preventDefault();
				addtocart(this);
			});
			if ($(this).attr('type') == "button") 
				$(this).click(function() { $(form).submit(); });
		});
	})(jQuery)
}

function validateForms () {
	jQuery('form.validate').submit(function () {
		if (validate(this)) return true;
		else return false;
	});
}

/**
 * catalogViewHandler ()
 * Handles catalog view changes
 **/
function catalogViewHandler () {
	var $=jQuery.noConflict();
	
	var display = $('#shopp');
	var expires = new Date();
	expires.setTime(expires.getTime()+(30*86400000));

	var category = $(this);
	display.find('ul.views li button.list').click(function () {
		display.removeClass('grid').addClass('list');
		document.cookie = 'shopp_catalog_view=list; expires='+expires+'; path=/';
	});
	display.find('ul.views li button.grid').click(function () {
		display.removeClass('list').addClass('grid');
		document.cookie = 'shopp_catalog_view=grid; expires='+expires+'; path=/';
	});
}

/**
 * cartHandlers ()
 * Adds behaviors to shopping cart controls
 **/
function cartHandlers () {
	jQuery('#cart #shipping-country').change(function () {
		this.form.submit();
	});
}

function shopp_gallery (id,evt) {
	(function($) {
		if (!evt) evt = 'click';
		var gallery = $(id);
		var thumbnails = gallery.find('ul.thumbnails li');
		var previews = gallery.find('ul.previews');
	
		thumbnails.bind(evt,function () {
			var target = $('#'+$(this).attr('class').split(' ')[0]);
			if (!target.hasClass('active')) {
				var previous = gallery.find('ul.previews li.active');
				target.addClass('active').hide();
				if (previous.length) {
					previous.fadeOut(800,function() {
						$(previous).removeClass('active');
					});
				}
				target.appendTo(previews).fadeIn(500);
			}
		});
		
	})(jQuery)
}

var Slideshow = function (element,duration,delay,fx,order) {
	var $ = jQuery.noConflict();
	var _ = this;
	this.element = $(element);
	var effects = {
		'fade':[{'display':'none'},{'opacity':'show'}],
		'slide-down':[{'display':'block','top':this.element.height()*-1},{'top':0}],
		'slide-up':[{'display':'block','top':this.element.height()},{'top':0}],
		'slide-left':[{'display':'block','left':this.element.width()*-1},{'left':0}],
		'slide-right':[{'display':'block','left':this.element.width()},{'left':0}],
		'wipe':[{'display':'block','height':0},{'height':this.element.height()}]
	};
	var ordering = ['normal','reverse','shuffle'];
	
	this.duration = (!duration)?800:duration;
	this.delay = (!delay)?7000:delay;
	fx = (!fx)?'fade':fx;
	this.effect = (!effects[fx])?effects['fade']:effects[fx];
	order = (!order)?'normal':order;
	this.order = ($.inArray(order,ordering) != -1)?order:'normal';
	
	this.slides = $(this.element).find('li:not(li.clear)').hide().css('visibility','visible');;
	this.total = this.slides.length;
	this.slide = 0;
	this.shuffling = new Array();
	this.startTransition = function () {
		var prev = $(self.slides).find('.active').removeClass('active');
		$(_.slides[_.slide]).css(_.effect[0]).appendTo(_.element).animate(
				_.effect[1],
				_.duration,
				function () {
					prev.css(_.effect[0]);
				}
			).addClass('active');

		switch (_.order) {
			case "shuffle": 
				if (_.shuffling.length == 0) {
					_.shuffleList();
					var index = $.inArray(_.slide,_.shuffling);
					if (index != -1) _.shuffling.splice(index,1);						
				}
				var selected = Math.floor(Math.random()*_.shuffling.length);
				_.slide = _.shuffling[selected];
				_.shuffling.splice(selected,1);
				break;
			case "reverse": _.slide = (_.slide-1 < 0)?_.slides.length-1:_.slide-1; break;
			default: _.slide = (_.slide+1 == _.total)?0:_.slide+1;
		}
		
		if (_.slides.length == 1) return;
		setTimeout(_.startTransition,_.delay);
	}
	
	this.transitionTo = function (slide) {
		this.slide = slide;
		this.startTransition();
	}
	
	this.shuffleList = function () {
		for (var i = 0; i < this.total; i++) this.shuffling.push(i);
	}
	
	this.startTransition();
}

function slideshows () {
	jQuery('ul.slideshow').each(function () {
		var $ = jQuery.noConflict();
		var classes = $(this).attr('class');
		var options = {};
		var map = {
			'fx':new RegExp(/([\w_-]+?)\-fx/),
			'order':new RegExp(/([\w_-]+?)\-order/),
			'duration':new RegExp(/duration\-(\d+)/),
			'delay':new RegExp(/delay\-(\d+)/)
		};
		$.each(map,function (name,pattern) {
			if (option = classes.match(pattern)) options[name] = option[1];
		});
		new Slideshow(this,options['duration'],options['delay'],options['fx'],options['order']);
	});
}

var Carousel = function (element,duration) {
	var $ = jQuery.noConflict(),
		_ = this,
		carousel = $(element),
		list = carousel.find('ul'),
		items = list.find('> li');

	this.duration = (!duration)?800:duration;
	this.cframe = carousel.find('div.frame');

	var visible = Math.floor(this.cframe.innerWidth() / items.outerWidth()),
		spacing = Math.round(((this.cframe.innerWidth() % items.outerWidth())/items.length)/2);

	items.css('margin','0 '+spacing+'px');
		
	this.pageWidth = (items.outerWidth()+(spacing*2)) * visible;
	this.page = 1;
	this.pages = Math.ceil(items.length / visible);
	
	// Fill in empty slots
	if ((items.length % visible) != 0) {
		list.append( new Array(visible - (items.length % visible)+1).join('<li class="empty" style="width: '+items.outerWidth()+'px; height: 1px; margin: 0 '+spacing+'px"/>') );
		items = list.find('> li');
	}
	
	items.filter(':first').before(items.slice(-visible).clone().addClass('cloned'));
	items.filter(':last').after(items.slice(0,visible).clone().addClass('cloned'));
	items = list.find('> li');
	
	this.cframe.scrollLeft(this.pageWidth);

	this.scrollLeft = carousel.find('button.left');
	this.scrollRight = carousel.find('button.right');
	
	this.scrolltoPage = function (page) {
		var dir = page < _.page?-1:1,
			delta = Math.abs(_.page-page),
			scrollby = _.pageWidth*dir*delta;
		
		_.cframe.filter(':not(:animated)').animate({
			'scrollLeft':'+='+scrollby
		},_.duration,function() {
			if (page == 0) {
				_.cframe.scrollLeft(_.pageWidth*_.pages);
				page = _.pages;
			} else if (page > _.pages) {
				_.cframe.scrollLeft(_.pageWidth);
				page = 1;
			}
			_.page = page;
		});
	}
	
	this.scrollLeft.click(function () {
		return _.scrolltoPage(_.page-1);
	});

	this.scrollRight.click(function () {
		return _.scrolltoPage(_.page+1);
	});
	
}
function carousels () {
	jQuery('div.carousel').each(function () {
		var $ = jQuery.noConflict();
		var classes = $(this).attr('class');
		var options = {};
		var map = { 'duration':new RegExp(/duration\-(\d+)/) };
		$.each(map,function (name,pattern) {
			if (option = classes.match(pattern)) options[name] = option[1];
		});
		new Carousel(this,options['duration']);
	});
}

function htmlentities (string) {
	if (!string) return "";
	string = string.replace(new RegExp(/&#(\d+);/g), function() {
		return String.fromCharCode(RegExp.$1);
	});
	return string;
}

function PopupCalendar (target,month,year) {
	
	var _ = this;
	var $=jQuery.noConflict();
	
	var DAYS_IN_MONTH = new Array(new Array(0, 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31),
								  new Array(0, 31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31)
								 );
	var MONTH_NAMES = new Array('',ShoppSettings.month_jan, ShoppSettings.month_feb, ShoppSettings.month_mar, 
									ShoppSettings.month_apr, ShoppSettings.month_may, ShoppSettings.month_jun, 
									ShoppSettings.month_jul, ShoppSettings.month_aug, ShoppSettings.month_sep, 
									ShoppSettings.month_oct, ShoppSettings.month_nov, ShoppSettings.month_dec);
	
	var WEEK_DAYS = new Array(ShoppSettings.weekday_sun, ShoppSettings.weekday_mon,ShoppSettings.weekday_tue, 
								ShoppSettings.weekday_wed, ShoppSettings.weekday_thu, ShoppSettings.weekday_fri, 
								ShoppSettings.weekday_sat);
	
	/* Date Constants */
	var K_FirstMissingDays = 639787; /* 3 Sep 1752 */
	var K_MissingDays = 11; /* 11 day correction */
	var K_MaxDays = 42; /* max slots in a calendar map array */ 
	var K_Thursday = 4; /* for reformation */ 
	var K_Saturday = 6; /* 1 Jan 1 was a Saturday */ 
	var K_Sept1752 = new Array(30, 31, 1, 2, 14, 15, 16, 
							   17, 18, 19, 20, 21, 22, 23, 
							   24, 25, 26, 27, 28, 29, 30, 
							   -1, -1, -1, -1, -1, -1, -1, 
							   -1, -1, -1, -1, -1, -1, -1, 
							   -1, -1, -1, -1, -1, -1, -1
							  );
	var today = new Date();
	today = new Date(today.getFullYear(),today.getMonth(),today.getDate());
	var calendar = new Array();
	var dates = new Array();
	var selection = new Date();
	_.selection = selection;
	var scope = "month";
	_.scope = scope;
	var scheduling = true;
	_.scheduling = scheduling;

	this.render = function (month,day,year) {
		$(target).empty();

		if (!month) month = today.getMonth()+1;	
		if (!year) year = today.getFullYear();
		
		dates = this.getDayMap(month, year,0,true);
		var dayLabels = new Array();
		var weekdays = new Array();
		var weeks = new Array();

		var last_month = (month - 1 < 1)? 12: month - 1;
		var next_month = (month + 1 > 12)? 1: month + 1;
		var lm_year  = (last_month == 12)? year - 1: year;
		var nm_year  = (next_month == 1)? year + 1: year;
		
		var i = 0,w = 0;
		
		var backarrow = $('<span class="back">&laquo;</span>').appendTo(target);
		var previousMonth = new Date(year,month-2,today.getDate());
		if (!_.scheduling || (_.scheduling && previousMonth >= today.getTime())) {
			backarrow.click(function () {
				_.scope = "month";
				_.selection = new Date(year,month-2);
				_.render(_.selection.getMonth()+1,1,_.selection.getFullYear());
				$(_).change();
			});
		}
		var nextarrow = $('<span class="next">&raquo;</span>').appendTo(target);
		nextarrow.click(function () {
			_.scope = "month";
			_.selection = new Date(year,month);
			_.render(_.selection.getMonth()+1,1,_.selection.getFullYear());
			$(_).change();
		});
		
		var title = $('<h3></h3>').appendTo(target);
		$('<span class="month">'+MONTH_NAMES[month]+'</span>').appendTo(title);
		$('<span class="year">'+year.toString()+'</span>').appendTo(title);
		
		weeks[w] = $('<div class="week"></week>').appendTo(target);
		for (i = 0; i < WEEK_DAYS.length; i++) {
		 	var dayname = WEEK_DAYS[i];
		 	dayLabels[i] = $('<div class="label">'+dayname.substr(0,3)+'</span>').appendTo(weeks[w]);
		}
		
		for (i = 0; i < dates.length; i++) {
			var thisMonth = dates[i].getMonth()+1;
			var thisYear = dates[i].getFullYear();
			var thisDate = new Date(thisYear,thisMonth-1,dates[i].getDate());
			
			// Start a new week
			if (i % 7 == 0) weeks[++w] = $('<div class="week"></div>').appendTo(target);
			if (dates[i] != -1) {
				calendar[i] = $('<div title="'+i+'">'+thisDate.getDate()+'</div>').appendTo(weeks[w]);
				calendar[i].date = thisDate;

				if (thisMonth != month) calendar[i].addClass('disabled');
				if (_.scheduling && thisDate.getTime() < today.getTime()) calendar[i].addClass('disabled');
				if (thisDate.getTime() == today.getTime()) calendar[i].addClass('today');

				calendar[i].hover(function () {
					$(this).addClass('hover');
				},function () {
					$(this).removeClass('hover');
				});
				
				calendar[i].mousedown(function () { $(this).addClass('active');	});
				calendar[i].mouseup(function () { $(this).removeClass('active'); });
				
				
				if (!_.scheduling || (_.scheduling && thisDate.getTime() >= today.getTime())) {
					calendar[i].click(function () {
						_.resetCalendar();
						if (!$(this).hasClass("disabled")) $(this).addClass("selected");
						
						_.selection = dates[$(this).attr('title')];
						_.scope = "day";

						if (_.selection.getMonth()+1 != month) {
	 						_.render(_.selection.getMonth()+1,1,_.selection.getFullYear());
							_.autoselect();
						} else {
							$(target).hide();
						}
						$(_).change();
					});
				}
			}
		}
		
		
	}
	
	this.autoselect = function () {
		for (var i = 0; i < dates.length; i++) 
			if (dates[i].getTime() == this.selection.getTime())
				calendar[i].addClass('selected');
	}
	
	this.resetCalendar = function () {
		for(var i = 0; i < calendar.length; i++)
			$(calendar[i]).removeClass('selected');
	}
	
	/**
	 * getDayMap()
	 * Fill in an array of 42 integers with a calendar.  Assume for a moment 
	 * that you took the (maximum) 6 rows in a calendar and stretched them 
	 * out end to end.  You would have 42 numbers or spaces.  This routine 
	 * builds that array for any month from Jan. 1 through Dec. 9999. 
	 **/ 
	this.getDayMap = function (month, year, start_week, all) {
		var day = 1;
		var c = 0;
		var days = new Array();
		var last_month = (month - 1 == 0)? 12: month - 1;
		var last_month_year = (last_month == 12)? year - 1: year;
	
		if(month == 9 && year == 1752) return K_Sept1752;
		
		for(var i = 0; i < K_MaxDays; i++) {
			days.push(-1);
		}
	
		var pm = DAYS_IN_MONTH[(this.is_leapyear(last_month_year))?1:0][last_month];	// Get the last day of the previous month
		var dm = DAYS_IN_MONTH[(this.is_leapyear(year))?1:0][month];			// Get the last day of the selected month
		var dw = this.dayInWeek(1, month, year, start_week); // Find where the 1st day of the month starts in the week
		var pw = this.dayInWeek(1, month, year, start_week); // Find the 1st day of the last month in the week
			
		if (all) while(pw--) days[pw] = new Date(last_month_year,last_month-1,pm--);
		while(dm--) days[dw++] = new Date(year,month-1,day++);
		var ceiling = days.length - dw;
		if (all) while(c < ceiling)
			days[dw++] = new Date(year,month,++c);
		
		return days;
	} 

	/* dayInYear() -- 
	 * Return the day of the year */ 
	this.dayInYear = function (day, month, year) {
	    var leap = (this.is_leapyear( year ))?1:0; 
	    for(var i = 1; i < month; i++) {
			day += DAYS_IN_MONTH[leap][i];
		}
	    return day;
	} 

	/* dayInWeek() -- 
	 * return the x based day number for any date from 1 Jan. 1 to 
	 * 31 Dec. 9999.  Assumes the Gregorian reformation eliminates 
	 * 3 Sep. 1752 through 13 Sep. 1752.  Returns Thursday for all 
	 * missing days. */ 
	this.dayInWeek = function (day, month, year, start_week) { 
		// Find 0 based day number for any date from Jan 1, 1 - Dec 31, 9999
		var daysSinceBC = (year - 1) * 365 + this.leapYearsSinceBC(year - 1) + this.dayInYear(day, month, year);
	    var val = K_Thursday;
	    // Set val 
		if(daysSinceBC < K_FirstMissingDays) val = ((daysSinceBC - 1 + K_Saturday ) % 7); 
		if(daysSinceBC >= (K_FirstMissingDays + K_MissingDays)) val = (((daysSinceBC - 1 + K_Saturday) - K_MissingDays) % 7);

	    // Shift depending on the start day of the week
	    if (val <= start_week) return val += (7 - start_week);
	    else return val -= start_week;

	} 
	
	this.is_leapyear = function (yr) {
		if (yr <= 1752) return !((yr) % 4);
		else return ((!((yr) % 4) && ((yr) % 100) > 0) || (!((yr) % 400)));
	}

	this.centuriesSince1700 = function (yr) {
		if (yr > 1700) return (Math.floor(yr / 100) - 17);
		else return 0;
	}

	this.quadCenturiesSince1700 = function (yr) {
		if (yr > 1600) return Math.floor((yr - 1600) / 400);
		else return 0;
	}

	this.leapYearsSinceBC = function (yr) {
		return (Math.floor(yr / 4) - this.centuriesSince1700(yr) + this.quadCenturiesSince1700(yr));
	}
	
}

var validate = function (form) {
	var $ = jQuery.noConflict();
	if (!form) return false;

	var passed = true,
		passwords = new Array(),
		error = new Array();

	var inputs = $(form).find('input,select');
	$.each(inputs,function (id,input) {
		if ($(input).attr('disabled') == true) return;
		
		if ($(input).hasClass('required') && $(input).val() == "")
			error = new Array(ShoppSettings.REQUIRED_FIELD.replace(/%s/,$(input).attr('title')),input);
		
		if ($(input).hasClass('required') && $(input).attr('type') == "checkbox" && !$(input).attr('checked'))
			error = new Array(ShoppSettings.REQUIRED_CHECKBOX.replace(/%s/,$(input).attr('title')),input);
		
		if ($(input).hasClass('email') && !$(input).val().match(new RegExp('^[a-zA-Z0-9._-]+@([a-zA-Z0-9.-]+\.)+[a-zA-Z0-9.-]{2,4}$')))
			error = new Array(ShoppSettings.INVALID_EMAIL,input);
			
		if (chars = $(input).attr('class').match(new RegExp('min(\\d+)'))) {
			if ($(input).val().length < chars[1])
				error = new Array(ShoppSettings.MIN_LENGTH.replace(/%s/,$(input).attr('title')).replace(/%d/,chars[1]),input);
		}
		
		if ($(input).hasClass('passwords')) {
			passwords.push(input);
			if (passwords.length == 2 && passwords[0].value != passwords[1].value)
				error = new Array(ShoppSettings.PASSWORD_MISMATCH,passwords[1]);
		}
			
	});

	if (error.length > 0) {
		error[1].focus();
		alert(error[0]);
		passed = false;
	}
	return passed;
}

jQuery(document).ready(function() {
	validateForms();
	formatFields();
	buttonHandlers();
	cartHandlers();
	catalogViewHandler();
	quickSelects();
	slideshows();
	carousels();
	if (jQuery.fn.colorbox) {
		jQuery('a.shopp-zoom').colorbox();
		jQuery('a.shopp-zoom.gallery').attr('rel','gallery').colorbox({slideshow:true});
	}
});

// Initialize placehoder variables
var helpurl;
var options_required;
var options_default;
var productOptions = new Array();