# Websource Précommandes — module Drupal Commerce

Système **complet et paramétrable** de précommandes pour Drupal Commerce : date de sortie, compte à rebours, prix de précommande, quotas, réservations, produit à la une, bandeau défilant, page « Toutes les précommandes », alertes e-mail avec double opt-in, récapitulatif périodique par cron et e-mails entièrement personnalisables.

Port du module PrestaShop [Websource Précommandes](https://github.com/Websource-fr/websourcepreorder).

- **Éditeur** : [Websource](https://www.websource.fr)
- **Version** : 1.0.0 — **Licence** : usage libre non commercial (voir [LICENSE](LICENSE))
- **Guide utilisateur** : joint à la [release](../../releases) (PDF)

## Compatibilité

| | |
|---|---|
| Drupal | 10.x et 11.x |
| Drupal Commerce | 3.x (testé 3.3.10) — 2.x : code écrit pour rester compatible, **non testé** |
| PHP | 8.1 et plus |
| Thèmes | Olivero et Claro testés ; tout thème standard (templates Twig surchargeables) |

**Niveau de validation** : testé de bout en bout sur Drupal 11.4.8 + Commerce 3.3.10 (navigateur, e-mails capturés, cron, désinstallation) ; scénario métier (prix, quotas, mélange, réservation, annulation) rejoué sur Drupal 10.6.18 + Commerce 3.3.10 en ligne de commande.

## Fonctionnalités

- **Règles de précommande** (entité de configuration) par produit ou par variation : activation, début, date de sortie, clôture automatique à la sortie, fin anticipée optionnelle, statuts Programmée / En cours / Terminée, action « Disponible maintenant ».
- **Prix de précommande** : prix fixe, remise en %, remise en montant, via un *price resolver* Commerce. Le produit n'est **jamais modifié**.
- **Commande hors stock** pendant la précommande : le module décore le gestionnaire de disponibilité de Commerce. Rien n'est écrit dans le stock : à la clôture, le comportement d'origine revient exactement.
- **Limites** : quota total, maximum par commande, maximum par client, option « interdire le mélange précommandes / produits en stock » — contrôlées dès l'ajout au panier, avec message clair.
- **Réservations** créées à la commande ; l'annulation ou le remboursement libère le quota. Écran d'administration (filtres, export CSV, renvoi d'e-mail, annulation) et page « Mes précommandes » dans le compte utilisateur.
- **Affichage** : badge, bloc fiche produit (date, compte à rebours, barre de progression, quantité restante, message), bouton renommé « Précommander », badge dans les listes, notice dans le panier.
- **Compte à rebours** : automatique, jours, jours+heures, jours+heures+minutes, avec secondes. Mise à jour en direct dans le navigateur.
- **Couleurs** : 9 variables CSS paramétrables.
- **Blocs placeables** : produit à la une, bandeau défilant (vitesse, nombre max, respect de `prefers-reduced-motion`), prochaines précommandes, alerte e-mail, bloc fiche produit.
- **Pages de compte** : sur `user.login`, `user.register` et `user.pass`, au choix, prochains produits et/ou formulaire d'alerte.
- **Page publique** « Toutes les précommandes » (chemin configurable), groupée par mois.
- **Alertes e-mail** : double opt-in, case RGPD, anti-spam (champ piège, délai minimum, jeton), désinscription en un clic ; écran Abonnés (ajout manuel, confirmation, suppression, export CSV).
- **Récapitulatif périodique** : *x* fois par jour / semaine / mois, via `hook_cron` **et** URL de cron sécurisée par jeton ; « toutes les précommandes ouvertes » ou « seulement les nouveautés » ; envois par lots avec reprise (Queue API) ; jamais d'envoi s'il n'y a rien.
- **E-mails personnalisables** (objet + corps HTML par langue, variables `{firstname}`, `{order_reference}`…), enveloppe HTML aux couleurs du site, bouton « Envoyer un test ».
- **Administration** : écran de réglages à onglets verticaux + tableau de bord rapide, permissions dédiées, schéma de configuration complet, désinstallation propre avec option « conserver les données ».

## Installation

1. Placez le dossier `websource_preorder` dans `modules/custom/` (ou `composer require` depuis votre dépôt si vous le référencez), puis activez-le :
   `drush en websource_preorder` ou *Extension* dans l'administration.
2. *Commerce → Précommandes → Règles → Ajouter une règle*, choisissez le produit ou la variation et la date de sortie.
3. *Commerce → Précommandes → Réglages* : libellés, couleurs, alertes, e-mails.
4. Placez les blocs voulus (*Structure → Mise en page des blocs*, catégorie « Websource Précommandes »).
5. Cron : le cron Drupal suffit ; vous pouvez aussi planifier l'URL sécurisée affichée dans l'onglet « Cron & récapitulatif ».

> Pour que le prix de précommande s'affiche sur la fiche produit, le champ prix de la variation doit utiliser le formateur **« Prix calculé »** (c'est le comportement par défaut du module si aucun affichage n'est configuré).

## Permissions

| Permission | Usage |
|---|---|
| Administrer les précommandes | Réglages, e-mails, cron |
| Gérer les règles de précommande | CRUD des règles |
| Consulter et gérer les réservations | Écran Réservations |
| Gérer les abonnés aux alertes | Écran Abonnés |
| Voir ses propres précommandes | « Mes précommandes » |
| Voir la page publique et les blocs d'affichage | Page liste |

À l'installation, les rôles anonyme et authentifié reçoivent la lecture de la page publique ; l'authentifié reçoit « Mes précommandes ».

## Personnaliser l'apparence

Chaque template est surchargeable depuis votre thème (`websource-preorder-box.html.twig`, `-badge`, `-countdown`, `-featured`, `-ticker`, `-list`, `-upcoming`, `-mine`). Les couleurs passent par les variables CSS `--wspo-*` ; le CSS/JS est chargé par la bibliothèque `websource_preorder/front`.

## Limites connues

- Paiement d'un acompte puis solde à la sortie : non géré (paiement intégral à la commande).
- Les limites « par client » s'appliquent aux clients identifiés (ou à l'e-mail de la commande).
- Une règle par produit/variation à la fois (la règle propre à la variation prime sur la règle « produit »).
- Le bloc fiche produit et le badge utilisent la variation par défaut/ la règle la plus avancée du produit ; le bloc n'est pas rafraîchi en AJAX au changement de variation.
- Le bloc « Bloc précommande (fiche produit) » est un complément pour les mises en page qui n'affichent pas le rendu standard du produit ; ne le placez pas en plus du bloc automatique (doublon).
- L'envoi de la confirmation de précommande et du récapitulatif passe par le système d'e-mail du site (PHP mail, SMTP, Symfony Mailer…) : le module délègue à celui configuré.
- Les chaînes sources sont en français ; `translations/fr.po` est le catalogue de référence pour traduire dans d'autres langues.
- La durée de vie du cache page des pages avec compte à rebours est limitée à 5 minutes au maximum.

## Structure

```
websource_preorder.info.yml / .module / .install
config/                    réglages par défaut et schéma
src/Entity                 règle de précommande (config entity)
src/Service                règles, réservations, abonnés, e-mails, récap, affichage
src/Availability           vérificateur de disponibilité + décorateur
src/PriceResolver          prix de précommande
src/EventSubscriber        réservations à la commande / annulation
src/Plugin                 blocs, file d'attente, e-mail HTML
src/Form, src/Controller   administration et pages publiques
templates/ css/ js/        affichage
translations/fr.po         catalogue de traduction
```
