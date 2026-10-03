# Changelog

## 1.0.1 — 2026-10-03

- Ajout d'un encart « accompagnement Websource » (« Besoin d'aller plus loin ? ») : visible uniquement dans les pages d'administration du module (`/admin/commerce/preorder…`) et uniquement pour les utilisateurs ayant la permission « Administrer les précommandes ». Jamais en front ni dans les e-mails ; masquable 30 jours (localStorage) ; aucune requête externe. Les boutons « Nous contacter » / « Prendre rendez-vous » transmettent paramètres `utm_*` et `ws_*` (nom du module, version, version de Drupal, schéma+domaine du site), mention de transparence affichée sous les boutons. Template unique réutilisable : `templates/websource-preorder-support.html.twig` (bibliothèque `websource_preorder/support`).

## 1.0.0 — 2026-10-03

Première version : port complet du module PrestaShop « Websource Précommandes » vers Drupal Commerce.

- Règles de précommande (config entity) par produit ou variation, statuts Programmée / En cours / Terminée, action « Disponible maintenant ».
- Prix de précommande par *price resolver* (fixe, % ou montant), sans modifier le produit.
- Commande hors stock par décoration du gestionnaire de disponibilité ; quota, maximum par commande/client, interdiction du mélange.
- Réservations (table dédiée), libération du quota à l'annulation / au remboursement / à la suppression de la commande.
- Écrans Réservations et Abonnés (filtres, export CSV, renvoi d'e-mail, annulation), page « Mes précommandes ».
- Badge, bloc fiche produit, compte à rebours (5 modes), barre de progression, quantité restante, bouton « Précommander », notice panier.
- Blocs : produit à la une, bandeau défilant, prochaines précommandes, alerte e-mail, bloc fiche produit.
- Blocs sur connexion / inscription / mot de passe oublié.
- Page publique « Toutes les précommandes » (chemin configurable, groupée par mois).
- Alertes e-mail : double opt-in, RGPD, honeypot, délai minimum, jeton, désinscription en un clic.
- Récapitulatif périodique (hook_cron + URL sécurisée, lots Queue API avec reprise).
- E-mails personnalisables par langue, enveloppe HTML, envoi de test.
- Réglages à onglets verticaux, 9 couleurs, permissions, schéma de configuration, désinstallation avec option « conserver les données ».
