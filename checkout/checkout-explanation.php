<!-- <ul class="form-list" style="display:none"> -->
<?php
global $woocommerce;
$order_total = $woocommerce->cart->total;
$l10n_total = "$" . number_format($order_total, 2);
$first_installment_amount=round($order_total*0.35, 2);
$remainder_installment_amount=$order_total-$first_installment_amount;
$amount = round( $remainder_installment_amount / 3, 2);

$remainder = $order_total - ($first_installment_amount+($amount*3));

/*if($remainder == 0){
    $remainder = $amount;
}*/

if($remainder!=0)
{
	$first_installment_amount=$first_installment_amount+$remainder;
}
?>

<?php if( $pil_environment !== "LIVE"): ?>

    <div class="woocommerce-error">
        <h4><?php echo esc_html($pil_environment); ?> mode only - charges won't be processed. Use production keys.</h4>
       <!--  Enter your production keys to process payments correctly. <br> <br>
        <strong>Can't find your production keys?</strong> Send us an email once you've finished testing and we'll supply them. -->
    </div>
<?php endif; ?>
<strong>$<?php echo esc_html(number_format($first_installment_amount, 2)); ?></strong> will be deducted from your credit card today, followed by $<?php echo esc_html(number_format($amount, 2)); ?>  automatically every 7 days.
<br><br>
Proceed through checkout to finalise this transaction with <strong>PayItLater</strong>.

<ul class="breakup payitlater">
    <li><div class="date">Today</div>
    <div class="amount"><?php echo esc_html("$" . number_format($first_installment_amount, 2)); ?></div></li>
    <li class="second"><div class="date">
            <?php echo esc_html(date("j M", mktime(0, 0, 0, date("m"), date("d") + 7, date("Y")))); ?>
        </div>
        <div class="amount"><?php echo esc_html("$" . number_format($amount, 2)); ?></div></li>
    <li class="third"><div class="date">
            <?php echo esc_html(date("j M", mktime(0, 0, 0, date("m"), date("d") + 14, date("Y")))); ?></div>
        <div class="amount"><?php echo esc_html("$" . number_format($amount, 2)); ?></div></li>
    <li class="last"><div class="date">
            <?php echo esc_html(date("j M", mktime(0, 0, 0, date("m"), date("d") + 21, date("Y")))); ?></div>
        <div class="amount"><?php echo esc_html("$" . number_format( $order_total - ($first_installment_amount+($amount * 2)), 2)); ?></div></li>
</ul>
<div class="instalment-footer">
    <p>You'll be redirected to our website to complete the transaction.</p>
    <a href="https://www.payitlater.com.au/terms/" target="_blank">Terms & Conditions</a> /


    <a class="js-open-payitlater-modal" href="#">About PayItLater</a>
</div>

<style>
    ul.breakup.payitlater li .amount {
        color: #fff;
    }

    ul.breakup.payitlater li.last .amount {
        color: #387fbc;
    }
    ul.breakup.payitlater.small-space {
        margin: 0 auto;
        padding-left:0;
    }

    ul.breakup.payitlater.small-space li {
        margin-right:8px;
    }
    ul.breakup.payitlater.small-space li.last {
        margin-right: 0 !important;
    }
</style>
<script type="text/javascript">

// check width of parent. 
if(jQuery){
    jQuery(function($){
    var width = $(".breakup.payitlater").width();

    console.log(width);

        if(width < 500){
            $(".breakup.payitlater").addClass("small-space");
        }
        else{
            $(".breakup.payitlater").removeClass("small-space");
        }
    });

}

</script>
