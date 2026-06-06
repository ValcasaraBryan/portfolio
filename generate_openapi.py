#!/usr/bin/env python3
"""
Génère site/api/openapi.yaml à partir de la définition Python.

Usage :
    python3 generate_openapi.py

Pour ajouter un endpoint :
  1. Ajouter le schema dans SCHEMAS si nécessaire
  2. Ajouter le path dans PATHS
  3. Relancer le script
"""

import yaml
import os

OUTPUT = os.path.join(os.path.dirname(__file__), 'site', 'api', 'openapi.yaml')

# ─── Helpers ────────────────────────────────────────────────────────────────────

def ref(name):
    return {'$ref': f'#/components/schemas/{name}'}

def json_content(schema):
    return {'application/json': {'schema': schema}}

def err(description):
    return {'description': description, 'content': json_content(ref('Error'))}

def ok(description, schema):
    return {'description': description, 'content': json_content(schema)}

def ok_list(description, item_schema_name):
    return ok(description, {'type': 'array', 'items': ref(item_schema_name)})

def ok_id(description='Ressource créée'):
    return ok(description, {'type': 'object', 'properties': {'id': {'type': 'integer'}}})

def ok_success(description='Opération réussie'):
    return ok(description, ref('Success'))

def body(schema_name):
    return {'required': True, 'content': json_content(ref(schema_name))}

def bearer():
    return [{'BearerAuth': []}]

def id_param():
    return {'name': 'id', 'in': 'path', 'required': True, 'schema': {'type': 'integer'}}

def lang_param():
    return {'name': 'lang', 'in': 'query',
            'schema': {'type': 'string', 'enum': ['fr', 'en'], 'default': 'fr'}}

def std_write_responses(perm, extra=None):
    r = {
        '200': ok_success(),
        '401': err('Non authentifié'),
        '403': err(f'Permission `{perm}` manquante'),
    }
    if extra:
        r.update(extra)
    return r

def std_delete_responses(perm):
    return {
        '200': ok_success(),
        '403': err(f'Permission `{perm}` manquante'),
        '422': err('ID manquant'),
    }

def skills_get(tag, op_id):
    return {
        'tags': [tag],
        'summary': f'Skills liés (V2, public)',
        'operationId': op_id,
        'parameters': [id_param(), lang_param()],
        'responses': {'200': ok_list('Liste des compétences associées', 'SkillRef')},
    }

def skills_put(tag, op_id, perm):
    return {
        'tags': [tag],
        'summary': f'Synchroniser les skills (V2)',
        'description': 'Remplace la liste complète des skills associés (DELETE + INSERT).',
        'operationId': op_id,
        'security': bearer(),
        'parameters': [id_param()],
        'requestBody': body('SkillSyncRequest'),
        'responses': {
            '200': ok_success('Association mise à jour'),
            '401': err('Non authentifié'),
            '403': err(f'Permission `{perm}` manquante'),
            '422': err('skill_ids manquant ou invalide'),
        },
    }

# ─── Schemas ─────────────────────────────────────────────────────────────────────

SCHEMAS = {

    # Utilitaires
    'Error': {
        'type': 'object',
        'properties': {'error': {'type': 'string', 'example': 'Invalid credentials'}},
    },
    'Success': {
        'type': 'object',
        'properties': {'success': {'type': 'boolean', 'example': True}},
    },

    # Auth V2
    'LoginRequest': {
        'type': 'object',
        'required': ['username', 'password'],
        'properties': {
            'username': {'type': 'string', 'example': 'admin'},
            'password': {'type': 'string', 'format': 'password', 'example': 's3cr3t'},
        },
    },
    'LoginResponse': {
        'type': 'object',
        'properties': {
            'token': {'type': 'string', 'description': 'JWT access token (expiration 1 heure)'},
            'refresh_token': {'type': 'string'},
            'expires_in': {'type': 'integer', 'example': 3600},
            'user': ref('AdminUser'),
        },
    },
    'RefreshRequest': {
        'type': 'object',
        'required': ['refresh_token'],
        'properties': {'refresh_token': {'type': 'string'}},
    },
    'RefreshResponse': {
        'type': 'object',
        'properties': {
            'token': {'type': 'string'},
            'refresh_token': {'type': 'string'},
            'expires_in': {'type': 'integer', 'example': 3600},
        },
    },

    # Admin users
    'AdminUser': {
        'type': 'object',
        'properties': {
            'id': {'type': 'integer'},
            'username': {'type': 'string'},
            'email': {'type': 'string', 'format': 'email'},
            'role': {'type': 'string', 'enum': ['superadmin', 'admin', 'editor']},
            'permissions': {
                'type': 'object',
                'nullable': True,
                'description': (
                    'Permissions granulaires (null = droits du rôle par défaut). '
                    'Structure : { "feature": { "read": true, "write": true, "delete": false } }'
                ),
            },
            'last_login_at': {'type': 'string', 'format': 'date-time', 'nullable': True},
            'created_at': {'type': 'string', 'format': 'date-time'},
        },
    },
    'AdminUserWriteRequest': {
        'type': 'object',
        'required': ['username', 'password'],
        'properties': {
            'username': {'type': 'string'},
            'password': {'type': 'string', 'format': 'password', 'minLength': 8},
            'email': {'type': 'string', 'format': 'email'},
            'role': {'type': 'string', 'enum': ['superadmin', 'admin', 'editor'], 'default': 'editor'},
            'permissions': {'type': 'object', 'nullable': True},
        },
    },

    # Skills
    'Skill': {
        'type': 'object',
        'properties': {
            'id': {'type': 'integer'},
            'name': {'type': 'string'},
            'category': {'type': 'string'},
            'category_description': {'type': 'string', 'nullable': True},
            'category_color': {'type': 'string', 'example': '#7ED9B1'},
            'skill_description': {'type': 'string', 'nullable': True},
        },
    },
    'SkillWriteRequest': {
        'type': 'object',
        'required': ['name_fr', 'name_en', 'category_key'],
        'properties': {
            'name_fr': {'type': 'string'},
            'name_en': {'type': 'string'},
            'description_fr': {'type': 'string'},
            'description_en': {'type': 'string'},
            'category_key': {
                'type': 'string',
                'description': 'Clé canonique de la catégorie (ex. "frontend", "backend")',
            },
        },
    },
    'SkillRef': {
        'type': 'object',
        'properties': {
            'id': {'type': 'integer'},
            'name': {'type': 'string'},
        },
    },
    'SkillSyncRequest': {
        'type': 'object',
        'required': ['skill_ids'],
        'properties': {
            'skill_ids': {
                'type': 'array',
                'items': {'type': 'integer'},
                'example': [1, 3, 7],
            },
        },
    },

    # Categories
    'Category': {
        'type': 'object',
        'properties': {
            'id': {'type': 'integer'},
            'key': {'type': 'string', 'description': 'Clé canonique (immuable après création)'},
            'name_fr': {'type': 'string'},
            'name_en': {'type': 'string'},
            'description_fr': {'type': 'string', 'nullable': True},
            'description_en': {'type': 'string', 'nullable': True},
            'color': {'type': 'string', 'pattern': '^#[0-9A-Fa-f]{6}$', 'example': '#7ED9B1'},
            'sort_order': {'type': 'integer'},
        },
    },
    'CategoryWriteRequest': {
        'type': 'object',
        'required': ['key'],
        'properties': {
            'key': {'type': 'string', 'description': 'Clé canonique anglaise (immuable après création)'},
            'name_fr': {'type': 'string'},
            'name_en': {'type': 'string'},
            'description_fr': {'type': 'string'},
            'description_en': {'type': 'string'},
            'color': {'type': 'string', 'pattern': '^#[0-9A-Fa-f]{6}$', 'default': '#888888'},
            'sort_order': {'type': 'integer'},
        },
    },

    # Educations / formations
    'Education': {
        'type': 'object',
        'properties': {
            'id': {'type': 'integer'},
            'school': {'type': 'string'},
            'city': {'type': 'string'},
            'start_date': {'type': 'string', 'format': 'date'},
            'end_date': {'type': 'string', 'format': 'date', 'nullable': True},
            'mention': {'type': 'string', 'nullable': True},
            'title': {'type': 'string'},
            'level': {'type': 'string'},
            'description': {'type': 'string', 'nullable': True},
        },
    },
    'EducationWriteRequest': {
        'type': 'object',
        'required': ['school', 'title', 'level', 'city', 'start_date'],
        'properties': {
            'school': {'type': 'string'},
            'title': {'type': 'string'},
            'title_en': {'type': 'string'},
            'level': {'type': 'string'},
            'level_en': {'type': 'string'},
            'city': {'type': 'string'},
            'start_date': {'type': 'string', 'format': 'date'},
            'end_date': {'type': 'string', 'format': 'date', 'nullable': True},
            'description': {'type': 'string'},
            'description_en': {'type': 'string'},
            'mention': {'type': 'string'},
        },
    },

    # Experiences
    'ExperienceType': {
        'type': 'string',
        'enum': [
            'internship', 'permanent_contract', 'fixed_term_contract',
            'work_study', 'freelance', 'self_employed',
        ],
    },
    'Experience': {
        'type': 'object',
        'properties': {
            'id': {'type': 'integer'},
            'company': {'type': 'string'},
            'location': {'type': 'string'},
            'start_date': {'type': 'string', 'format': 'date'},
            'end_date': {'type': 'string', 'format': 'date', 'nullable': True},
            'type': ref('ExperienceType'),
            'role': {'type': 'string'},
            'description': {'type': 'string', 'nullable': True},
        },
    },
    'ExperienceWriteRequest': {
        'type': 'object',
        'required': ['company', 'role_fr', 'type', 'start_date'],
        'properties': {
            'company': {'type': 'string'},
            'role_fr': {'type': 'string'},
            'role_en': {'type': 'string'},
            'type': ref('ExperienceType'),
            'location': {'type': 'string', 'nullable': True},
            'start_date': {'type': 'string', 'format': 'date'},
            'end_date': {'type': 'string', 'format': 'date', 'nullable': True},
            'description_fr': {'type': 'string', 'nullable': True},
            'description_en': {'type': 'string', 'nullable': True},
        },
    },

    # Certifications
    'CertificationFull': {
        'type': 'object',
        'properties': {
            'id': {'type': 'integer'},
            'year': {'type': 'integer', 'nullable': True, 'example': 2024},
            'name': {'type': 'string'},
            'formation_id': {'type': 'integer', 'nullable': True},
            'formation_title': {'type': 'string', 'nullable': True},
        },
    },
    'CertificationWriteRequest': {
        'type': 'object',
        'required': ['name'],
        'properties': {
            'year': {'type': 'integer', 'nullable': True},
            'name': {'type': 'string'},
            'formation_id': {'type': 'integer', 'nullable': True},
        },
    },

    # Projects
    'Project': {
        'type': 'object',
        'properties': {
            'id': {'type': 'integer'},
            'name': {'type': 'string'},
            'description': {'type': 'string', 'nullable': True},
            'photo_url': {'type': 'string', 'nullable': True},
            'date': {'type': 'string', 'format': 'date', 'nullable': True},
            'url': {'type': 'string', 'format': 'uri', 'nullable': True},
            'github_url': {'type': 'string', 'format': 'uri', 'nullable': True},
            'is_favorite': {'type': 'boolean'},
            'category': {'type': 'string', 'enum': ['web', 'opensource', 'side'], 'nullable': True},
        },
    },
    'ProjectWriteRequest': {
        'type': 'object',
        'required': ['name_fr'],
        'properties': {
            'name_fr': {'type': 'string'},
            'name_en': {'type': 'string'},
            'description_fr': {'type': 'string', 'nullable': True},
            'description_en': {'type': 'string', 'nullable': True},
            'photo_url': {'type': 'string', 'nullable': True},
            'date': {'type': 'string', 'format': 'date', 'nullable': True},
            'url': {'type': 'string', 'format': 'uri', 'nullable': True},
            'github_url': {'type': 'string', 'format': 'uri', 'nullable': True},
            'category': {
                'type': 'string',
                'enum': ['web', 'opensource', 'side'],
                'nullable': True,
            },
            'is_favorite': {'type': 'integer', 'enum': [0, 1], 'default': 0},
        },
    },
}

# ─── Tags ────────────────────────────────────────────────────────────────────────

TAGS = [
    {'name': 'auth-v2',       'description': '**V2** — Authentification JWT Bearer. Refresh token rotation inclus.'},
    {'name': 'experiences',   'description': 'Expériences professionnelles'},
    {'name': 'projects',      'description': 'Projets du portfolio'},
    {'name': 'certifications','description': 'Certifications attachées aux formations'},
    {'name': 'educations',    'description': 'Formations / éducation (V2)'},
    {'name': 'skills',        'description': 'Compétences techniques'},
    {'name': 'categories',    'description': 'Catégories de compétences'},
    {'name': 'users',         'description': 'Gestion des utilisateurs admin (superadmin uniquement)'},
]

# ─── Paths ───────────────────────────────────────────────────────────────────────

PATHS = {

    # ── AUTH ──────────────────────────────────────────────────────────────────────

    '/api/v2/auth/login': {
        'post': {
            'tags': ['auth-v2'],
            'summary': 'Connexion (JWT)',
            'description': (
                'Retourne un access token (JWT, 1h) et un refresh token (7 jours).\n'
                'Rate-limitée à **5 tentatives échouées** par IP par 15 minutes.'
            ),
            'operationId': 'v2AuthLogin',
            'requestBody': body('LoginRequest'),
            'responses': {
                '200': ok('Connexion réussie', ref('LoginResponse')),
                '400': err('Champs manquants'),
                '401': err('Identifiants incorrects'),
                '429': err('Trop de tentatives'),
            },
        },
    },
    '/api/v2/auth/logout': {
        'post': {
            'tags': ['auth-v2'],
            'summary': 'Déconnexion (révocation JWT)',
            'description': 'Révoque le JTI du token courant dans la table `jwt_revoked_tokens`.',
            'operationId': 'v2AuthLogout',
            'security': bearer(),
            'responses': {
                '200': ok('Token révoqué', {'type': 'object', 'properties': {'ok': {'type': 'boolean', 'example': True}}}),
                '401': err('Token manquant ou invalide'),
            },
        },
    },
    '/api/v2/auth/refresh': {
        'post': {
            'tags': ['auth-v2'],
            'summary': 'Rafraîchissement du token',
            'description': 'Échange un refresh token valide contre un nouveau couple access token / refresh token (rotation).',
            'operationId': 'v2AuthRefresh',
            'requestBody': body('RefreshRequest'),
            'responses': {
                '200': ok('Nouveau token émis', ref('RefreshResponse')),
                '400': err('refresh_token manquant'),
                '401': err('Refresh token invalide, expiré ou révoqué'),
            },
        },
    },
    '/api/v2/auth/me': {
        'get': {
            'tags': ['auth-v2'],
            'summary': 'Profil de l\'utilisateur courant',
            'operationId': 'v2AuthMe',
            'security': bearer(),
            'responses': {
                '200': ok('Données de l\'utilisateur authentifié', ref('AdminUser')),
                '401': err('Non authentifié'),
                '404': err('Utilisateur introuvable'),
            },
        },
    },
    '/api/v2/auth/exchange': {
        'get': {
            'tags': ['auth-v2'],
            'summary': 'Échange session PHP → JWT (superadmin)',
            'description': 'Permet à un superadmin connecté via la session PHP V1 d\'obtenir un JWT V2.',
            'operationId': 'v2AuthExchange',
            'security': [{'SessionCookie': []}],
            'responses': {
                '200': ok('JWT émis', ref('LoginResponse')),
                '401': err('Session PHP inactive'),
                '403': err('Rôle insuffisant (superadmin requis)'),
            },
        },
    },

    # ── SKILLS ────────────────────────────────────────────────────────────────────

    '/api/v2/skills': {
        'get': {
            'tags': ['skills'],
            'summary': 'Liste des compétences (public)',
            'operationId': 'v2SkillsList',
            'parameters': [lang_param()],
            'responses': {'200': ok_list('Liste des compétences', 'Skill')},
        },
        'post': {
            'tags': ['skills'],
            'summary': 'Créer une compétence',
            'operationId': 'v2SkillsCreate',
            'security': bearer(),
            'requestBody': body('SkillWriteRequest'),
            'responses': {
                '201': ok_id('Compétence créée'),
                '403': err('Permission `skills.write` manquante'),
            },
        },
    },
    '/api/v2/skills/{id}': {
        'put': {
            'tags': ['skills'],
            'summary': 'Mettre à jour une compétence',
            'operationId': 'v2SkillsUpdate',
            'security': bearer(),
            'parameters': [id_param()],
            'requestBody': body('SkillWriteRequest'),
            'responses': {
                '200': ok_success('Compétence mise à jour'),
                '403': err('Permission `skills.write` manquante'),
                '422': err('ID manquant'),
            },
        },
        'delete': {
            'tags': ['skills'],
            'summary': 'Supprimer une compétence',
            'operationId': 'v2SkillsDelete',
            'security': bearer(),
            'parameters': [id_param()],
            'responses': std_delete_responses('skills.delete'),
        },
    },

    # ── CATEGORIES ────────────────────────────────────────────────────────────────

    '/api/v2/categories': {
        'get': {
            'tags': ['categories'],
            'summary': 'Liste des catégories (public)',
            'operationId': 'v2CategoriesList',
            'responses': {'200': ok_list('Liste des catégories de compétences', 'Category')},
        },
        'post': {
            'tags': ['categories'],
            'summary': 'Créer une catégorie',
            'operationId': 'v2CategoriesCreate',
            'security': bearer(),
            'requestBody': body('CategoryWriteRequest'),
            'responses': {
                '201': ok_id('Catégorie créée'),
                '403': err('Permission `categories.write` manquante'),
            },
        },
    },
    '/api/v2/categories/{id}': {
        'put': {
            'tags': ['categories'],
            'summary': 'Mettre à jour une catégorie',
            'description': 'La clé (`key`) est immuable et ignorée si fournie.',
            'operationId': 'v2CategoriesUpdate',
            'security': bearer(),
            'parameters': [id_param()],
            'requestBody': body('CategoryWriteRequest'),
            'responses': {
                '200': ok_success('Catégorie mise à jour'),
                '403': err('Permission manquante'),
            },
        },
        'delete': {
            'tags': ['categories'],
            'summary': 'Supprimer une catégorie',
            'operationId': 'v2CategoriesDelete',
            'security': bearer(),
            'parameters': [id_param()],
            'responses': std_delete_responses('categories.delete'),
        },
    },
    '/api/v2/categories/{id}/skills': {
        'get': {
            'tags': ['categories'],
            'summary': 'Skills d\'une catégorie (public)',
            'operationId': 'v2CategoriesSkillsList',
            'parameters': [id_param(), lang_param()],
            'responses': {'200': ok_list('Liste des compétences de la catégorie', 'SkillRef')},
        },
    },

    # ── EDUCATIONS ────────────────────────────────────────────────────────────────

    '/api/v2/educations': {
        'get': {
            'tags': ['educations'],
            'summary': 'Liste des formations (public)',
            'operationId': 'v2EducationsList',
            'parameters': [lang_param()],
            'responses': {'200': ok_list('Liste des formations', 'Education')},
        },
        'post': {
            'tags': ['educations'],
            'summary': 'Créer une formation',
            'operationId': 'v2EducationsCreate',
            'security': bearer(),
            'requestBody': body('EducationWriteRequest'),
            'responses': {
                '201': ok_id('Formation créée'),
                '403': err('Permission `educations.write` manquante'),
            },
        },
    },
    '/api/v2/educations/{id}': {
        'put': {
            'tags': ['educations'],
            'summary': 'Mettre à jour une formation',
            'operationId': 'v2EducationsUpdate',
            'security': bearer(),
            'parameters': [id_param()],
            'requestBody': body('EducationWriteRequest'),
            'responses': {
                '200': ok_success('Formation mise à jour'),
                '403': err('Permission manquante'),
                '422': err('ID manquant'),
            },
        },
        'delete': {
            'tags': ['educations'],
            'summary': 'Supprimer une formation',
            'operationId': 'v2EducationsDelete',
            'security': bearer(),
            'parameters': [id_param()],
            'responses': std_delete_responses('educations.delete'),
        },
    },
    '/api/v2/educations/{id}/skills': {
        'get': skills_get('educations', 'v2EducationsSkillsList'),
        'put': skills_put('educations', 'v2EducationsSkillsSync', 'educations.write'),
    },

    # ── EXPERIENCES ───────────────────────────────────────────────────────────────

    '/api/v2/experiences': {
        'get': {
            'tags': ['experiences'],
            'summary': 'Liste des expériences (public)',
            'operationId': 'v2ExperiencesList',
            'parameters': [lang_param()],
            'responses': {'200': ok_list('Liste des expériences professionnelles', 'Experience')},
        },
        'post': {
            'tags': ['experiences'],
            'summary': 'Créer une expérience',
            'operationId': 'v2ExperiencesCreate',
            'security': bearer(),
            'requestBody': body('ExperienceWriteRequest'),
            'responses': {
                '201': ok_id('Expérience créée'),
                '401': err('Non authentifié'),
                '403': err('Permission `experiences.write` manquante'),
                '422': err('Type d\'expérience invalide'),
            },
        },
    },
    '/api/v2/experiences/{id}': {
        'put': {
            'tags': ['experiences'],
            'summary': 'Mettre à jour une expérience',
            'operationId': 'v2ExperiencesUpdate',
            'security': bearer(),
            'parameters': [id_param()],
            'requestBody': body('ExperienceWriteRequest'),
            'responses': {
                '200': ok_success('Expérience mise à jour'),
                '403': err('Permission `experiences.write` manquante'),
                '422': err('ID manquant ou type invalide'),
            },
        },
        'delete': {
            'tags': ['experiences'],
            'summary': 'Supprimer une expérience',
            'operationId': 'v2ExperiencesDelete',
            'security': bearer(),
            'parameters': [id_param()],
            'responses': std_delete_responses('experiences.delete'),
        },
    },
    '/api/v2/experiences/{id}/skills': {
        'get': skills_get('experiences', 'v2ExperiencesSkillsList'),
        'put': skills_put('experiences', 'v2ExperiencesSkillsSync', 'experiences.write'),
    },

    # ── CERTIFICATIONS ────────────────────────────────────────────────────────────

    '/api/v2/certifications': {
        'get': {
            'tags': ['certifications'],
            'summary': 'Liste des certifications (public)',
            'operationId': 'v2CertificationsList',
            'responses': {'200': ok_list('Liste des certifications', 'CertificationFull')},
        },
        'post': {
            'tags': ['certifications'],
            'summary': 'Créer une certification',
            'operationId': 'v2CertificationsCreate',
            'security': bearer(),
            'requestBody': body('CertificationWriteRequest'),
            'responses': {
                '201': ok_id('Certification créée'),
                '401': err('Non authentifié'),
                '403': err('Permission `certifications.write` manquante'),
            },
        },
    },
    '/api/v2/certifications/{id}': {
        'put': {
            'tags': ['certifications'],
            'summary': 'Mettre à jour une certification',
            'operationId': 'v2CertificationsUpdate',
            'security': bearer(),
            'parameters': [id_param()],
            'requestBody': body('CertificationWriteRequest'),
            'responses': {
                '200': ok_success('Certification mise à jour'),
                '403': err('Permission `certifications.write` manquante'),
                '422': err('ID manquant'),
            },
        },
        'delete': {
            'tags': ['certifications'],
            'summary': 'Supprimer une certification',
            'operationId': 'v2CertificationsDelete',
            'security': bearer(),
            'parameters': [id_param()],
            'responses': std_delete_responses('certifications.delete'),
        },
    },

    # ── PROJECTS ──────────────────────────────────────────────────────────────────

    '/api/v2/projects': {
        'get': {
            'tags': ['projects'],
            'summary': 'Liste des projets (public)',
            'operationId': 'v2ProjectsList',
            'parameters': [lang_param()],
            'responses': {'200': ok_list('Liste des projets', 'Project')},
        },
        'post': {
            'tags': ['projects'],
            'summary': 'Créer un projet',
            'operationId': 'v2ProjectsCreate',
            'security': bearer(),
            'requestBody': body('ProjectWriteRequest'),
            'responses': {
                '201': ok_id('Projet créé'),
                '401': err('Non authentifié'),
                '403': err('Permission `projects.write` manquante'),
                '422': err('Catégorie invalide'),
            },
        },
    },
    '/api/v2/projects/{id}': {
        'put': {
            'tags': ['projects'],
            'summary': 'Mettre à jour un projet',
            'operationId': 'v2ProjectsUpdate',
            'security': bearer(),
            'parameters': [id_param()],
            'requestBody': body('ProjectWriteRequest'),
            'responses': {
                '200': ok_success('Projet mis à jour'),
                '403': err('Permission `projects.write` manquante'),
                '422': err('ID manquant ou catégorie invalide'),
            },
        },
        'delete': {
            'tags': ['projects'],
            'summary': 'Supprimer un projet',
            'operationId': 'v2ProjectsDelete',
            'security': bearer(),
            'parameters': [id_param()],
            'responses': std_delete_responses('projects.delete'),
        },
    },
    '/api/v2/projects/{id}/skills': {
        'get': skills_get('projects', 'v2ProjectsSkillsList'),
        'put': skills_put('projects', 'v2ProjectsSkillsSync', 'projects.write'),
    },

    # ── USERS ─────────────────────────────────────────────────────────────────────

    '/api/v2/users': {
        'get': {
            'tags': ['users'],
            'summary': 'Liste des utilisateurs admin (superadmin)',
            'operationId': 'v2UsersList',
            'security': bearer(),
            'responses': {
                '200': ok_list('Liste des utilisateurs', 'AdminUser'),
                '403': err('Rôle superadmin requis'),
            },
        },
        'post': {
            'tags': ['users'],
            'summary': 'Créer un utilisateur admin (superadmin)',
            'operationId': 'v2UsersCreate',
            'security': bearer(),
            'requestBody': body('AdminUserWriteRequest'),
            'responses': {
                '201': ok_id('Utilisateur créé'),
                '403': err('Rôle superadmin requis'),
                '422': err('Mot de passe trop court (< 8 caractères) ou username déjà pris'),
            },
        },
    },
    '/api/v2/users/{id}': {
        'put': {
            'tags': ['users'],
            'summary': 'Mettre à jour un utilisateur admin (superadmin)',
            'description': (
                'Si `password` est fourni (≥ 8 chars), il est re-hashé et `must_change_password` est remis à 0.\n'
                'Protections : impossible de rétrograder/supprimer le dernier superadmin.'
            ),
            'operationId': 'v2UsersUpdate',
            'security': bearer(),
            'parameters': [id_param()],
            'requestBody': {
                'required': True,
                'content': json_content({
                    'type': 'object',
                    'properties': {
                        'email': {'type': 'string', 'format': 'email'},
                        'role': {'type': 'string', 'enum': ['superadmin', 'admin', 'editor']},
                        'permissions': {'type': 'object', 'nullable': True},
                        'password': {'type': 'string', 'format': 'password', 'minLength': 8},
                    },
                }),
            },
            'responses': {
                '200': ok_success('Utilisateur mis à jour'),
                '403': err('Rôle superadmin requis'),
                '422': err('Mot de passe invalide ou impossible de rétrograder le dernier superadmin'),
            },
        },
        'delete': {
            'tags': ['users'],
            'summary': 'Supprimer un utilisateur admin (superadmin)',
            'description': 'Protections : impossible de se supprimer soi-même ou de supprimer le dernier superadmin.',
            'operationId': 'v2UsersDelete',
            'security': bearer(),
            'parameters': [id_param()],
            'responses': {
                '200': ok_success('Utilisateur supprimé'),
                '403': err('Rôle superadmin requis'),
                '422': err('Impossible de supprimer soi-même ou le dernier superadmin'),
            },
        },
    },
    '/api/v2/users/{id}/permissions': {
        'patch': {
            'tags': ['users'],
            'summary': 'Mettre à jour les permissions d\'un utilisateur (superadmin)',
            'operationId': 'v2UsersUpdatePermissions',
            'security': bearer(),
            'parameters': [id_param()],
            'requestBody': {
                'required': True,
                'content': json_content({
                    'type': 'object',
                    'required': ['permissions'],
                    'properties': {
                        'permissions': {
                            'type': 'object',
                            'description': (
                                'Objet de permissions granulaires. '
                                'Structure : { "skills": { "read": true, "write": true, "delete": false } }'
                            ),
                        },
                    },
                }),
            },
            'responses': {
                '200': ok_success('Permissions mises à jour'),
                '403': err('Rôle superadmin requis'),
                '422': err('Champ `permissions` manquant'),
            },
        },
    },
}

# ─── Build & write ───────────────────────────────────────────────────────────────

SPEC = {
    'openapi': '3.0.0',
    'info': {
        'title': 'Portfolio API',
        'version': '2.0.0',
        'description': (
            'REST API v2 du portfolio de Bryan Valcasara.\n\n'
            'Authentification via JWT Bearer — se connecter via `POST /api/v2/auth/login`.\n'
            'Inclure `Authorization: Bearer <token>` dans chaque requête protégée.\n\n'
            '## Localisation\n'
            'Les endpoints GET publics acceptent `?lang=fr` ou `?lang=en` (défaut : `fr`).\n\n'
            '## Codes d\'erreur communs\n'
            '- `400` — Paramètres manquants ou invalides\n'
            '- `401` — Non authentifié\n'
            '- `403` — Droits insuffisants\n'
            '- `422` — Données métier invalides\n'
            '- `429` — Trop de tentatives (rate limiting)\n'
        ),
    },
    'servers': [
        {'url': '/', 'description': 'Racine du site (les chemins incluent /api/v2/)'},
    ],
    'components': {
        'securitySchemes': {
            'BearerAuth': {
                'type': 'http',
                'scheme': 'bearer',
                'bearerFormat': 'JWT',
                'description': 'Token JWT obtenu via `POST /api/v2/auth/login`. Expire après 1 heure.',
            },
            'SessionCookie': {
                'type': 'apiKey',
                'in': 'cookie',
                'name': 'PHPSESSID',
                'description': 'Cookie de session PHP. Utilisé uniquement pour `auth/exchange`.',
            },
        },
        'schemas': SCHEMAS,
    },
    'tags': TAGS,
    'paths': PATHS,
}


def main():
    yaml_str = yaml.dump(
        SPEC,
        default_flow_style=False,
        allow_unicode=True,
        sort_keys=False,
        width=120,
        indent=2,
    )
    with open(OUTPUT, 'w', encoding='utf-8') as f:
        f.write(yaml_str)
    print(f'✓ {OUTPUT} généré ({len(yaml_str.splitlines())} lignes)')


if __name__ == '__main__':
    main()
