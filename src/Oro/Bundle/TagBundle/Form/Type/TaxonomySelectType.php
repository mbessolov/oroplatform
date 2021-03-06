<?php

namespace Oro\Bundle\TagBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TaxonomySelectType extends AbstractType
{
    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(
            [
                'autocomplete_alias' => 'taxonomy',
                'configs'            => [
                    'placeholder' => 'oro.tag.form.choose_taxonomy'
                ],
            ]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getParent()
    {
        return 'oro_entity_select';
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'oro_taxonomy_select';
    }
}
