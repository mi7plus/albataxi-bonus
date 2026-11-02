<?php
declare(strict_types=1);
namespace Alba;

/** Human-readable Romanian labels; machine codes stay stable in the API. */
final class Labels {
 public static function state(string $value): string {
  return [
   'pending'=>'În așteptare','active'=>'Activ','suspended'=>'Suspendat','closed'=>'Închis',
   'draft'=>'De verificat','ready'=>'Pregătit','claimed'=>'Preluat','completed'=>'Finalizat',
   'posted'=>'Înregistrat','approved'=>'Aprobat','consumed'=>'Folosit','declined'=>'Refuzat',
   'cancelled'=>'Anulat','expired'=>'Expirat','revoked'=>'Revocat','accepted'=>'Acceptat',
   'under_review'=>'În verificare','partially_paid'=>'Plătit parțial','paid'=>'Plătit',
   'open'=>'Deschis','resolved'=>'Rezolvat','preview'=>'Previzualizare','manual_recovery'=>'Recuperare manuală','passed'=>'Verificat cu succes','failed'=>'Probleme găsite',
   'agency'=>'Agenție','independent'=>'Independent','driver'=>'Șofer','manager'=>'Manager','passenger'=>'Pasager',
   'earn'=>'Credit câștigat','redeem'=>'Credit folosit','reversal'=>'Corecție',
   'contribution_due'=>'Contribuție datorată','contribution_received'=>'Contribuție încasată',
   'reimbursement_due'=>'Rambursare datorată','reimbursement_paid'=>'Rambursare plătită',
  ][$value] ?? $value;
 }
 public static function totals(): array {
  return ['fare'=>'Tarif total','earned'=>'Credit câștigat · brut','earned_adjustment'=>'Corecții câștig',
   'net_earned'=>'Credit câștigat · net','redeemed'=>'Credit folosit · brut','redeemed_adjustment'=>'Corecții utilizare',
   'net_redeemed'=>'Credit folosit · net','net_credit'=>'Modificare netă a soldurilor','contribution'=>'Contribuții · brut',
   'contribution_adjustment'=>'Corecții contribuții','net_contribution'=>'Contribuții · net',
   'positive'=>'Intrări pozitive','negative'=>'Intrări negative','net'=>'Total net',
   'contribution_due'=>'Contribuții datorate · net','contribution_received'=>'Contribuții încasate',
   'reimbursement_due'=>'Rambursări datorate · net','reimbursement_paid'=>'Rambursări plătite'];
 }
}
