-- Remove os colaboradores de exemplo do calendário de férias e as férias deles.
SET NAMES utf8mb4;
START TRANSACTION;
DELETE f FROM glpi_plugin_rh_ferias f JOIN glpi_users u ON u.id = f.users_id WHERE u.name IN ('ana.ribeiro', 'bruno.alves', 'camila.rocha', 'diego.costa', 'gabriel.nunes', 'fernanda.duarte', 'helena.pires', 'igor.mendes', 'larissa.campos', 'rafael.moreira') AND u.comment = 'Exemplo RH (calendário de férias)';
DELETE FROM glpi_users WHERE name IN ('ana.ribeiro', 'bruno.alves', 'camila.rocha', 'diego.costa', 'gabriel.nunes', 'fernanda.duarte', 'helena.pires', 'igor.mendes', 'larissa.campos', 'rafael.moreira') AND comment = 'Exemplo RH (calendário de férias)';
COMMIT;
