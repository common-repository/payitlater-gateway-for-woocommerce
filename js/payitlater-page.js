
/*

jQuery(function(){
    jQuery('#what-is-payitlater').fancybox({
        afterLoad: function(){
            jQuery(".fancybox-skin").css({padding: 0});
        }
    });
});
*/


/* Build and add our modal window to the page. */
jQuery(function(){
    function closePilModal(){
        $(".payitlater-modal-bg").fadeOut(200);
    }

    function openPilModal(e){
        e.preventDefault();
        $(".payitlater-modal-bg").fadeIn(200);
    }
    var $ = jQuery;
    $("body").on('click', ".payitlater-modal-bg", closePilModal);
    $("body").on('click', ".payitlater-modal-win .panel-close", closePilModal);

    $("body").on('click',".js-open-payitlater-modal", openPilModal);
});
