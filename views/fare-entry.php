<form class="stack fare-entry" data-command="preview" data-financial data-draft-id="<?=e($draftId)?>" data-earn-bps="<?=e($driverRule['earn_bps']??0)?>" data-earning-paused="<?=$controls['earning_paused']?'1':'0'?>">
 <?php if($code!==''):?><input type="hidden" name="code" value="<?=e($code)?>"><?php else:?><label>Cod pasager<input name="code" autocomplete="off" maxlength="12" required></label><?php endif ?>
 <label>Tarif eligibil (lei)<input type="text" inputmode="decimal" name="fare" placeholder="0,00" autocomplete="off" required></label>
 <div class="fare-estimate"><span>Credit estimat pentru pasager</span><output data-earn-estimate>0,00 lei</output></div>
 <p class="fine">Calcul final verificat de server la pasul următor. Creditul devine disponibil după confirmarea cursei.</p>
 <button class="button">Verifică tariful și bonusul →</button>
</form>
