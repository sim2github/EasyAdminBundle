<?php

namespace EasyCorp\Bundle\EasyAdminBundle\Form\Type;

use EasyCorp\Bundle\EasyAdminBundle\Form\DataTransformer\URLToFileTransformer;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\Model\FileUploadState;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\OptionsResolver\Exception\InvalidArgumentException;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @author Yonel Ceruto <yonelceruto@gmail.com>
 */
class URLFileUploadType extends FileUploadType
{
    private $projectDir;

    public function __construct(string $projectDir)
    {
        $this->projectDir = $projectDir;
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(
            new URLToFileTransformer(
                $options['upload_dir'],
                $options['download_path'],
                $options['upload_filename'],
                $options['upload_validate'],
                $options['multiple']
            )
        );
        $builder->setAttribute('state', new FileUploadState($options['allow_add']));

        unset($options['upload_dir'],
        $options['upload_new'],
        $options['upload_delete'],
        $options['upload_filename'],
        $options['upload_validate'],
        $options['download_path'],
        $options['allow_add'],
        $options['allow_delete'],
        $options['compound']);

        $builder->add('file', FileType::class, $options);
        $builder->add('delete', CheckboxType::class, ['required' => false]);

        $builder->setDataMapper($this);
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $uploadNew = static function (UploadedFile $file, string $uploadDir, string $fileName) {
            $file->move(rtrim($uploadDir, '/\\').\DIRECTORY_SEPARATOR, $fileName);
        };

        $uploadDelete = static function (File $file) {
            unlink($file->getPathname());
        };

        $uploadFilename = static function (UploadedFile $file): string {
            return $file->getClientOriginalName();
        };

        $uploadValidate = static function (string $filename): string {
            if (!file_exists($filename)) {
                return $filename;
            }

            $index = 1;
            $pathInfo = pathinfo($filename);
            while (file_exists($filename = sprintf(
                '%s%s%s_%d.%s',
                $pathInfo['dirname'],
                \DIRECTORY_SEPARATOR,
                $pathInfo['filename'],
                $index,
                $pathInfo['extension']
            ))) {
                ++$index;
            }

            return $filename;
        };

        $downloadPath = function (Options $options) {
            $composer = json_decode(file_get_contents(
                $this->projectDir.\DIRECTORY_SEPARATOR.'composer.json'
            ), true);

            $public_path = \array_key_exists('public-dir', $composer['extra'])
                ? $composer['extra']['public-dir']
                : 'public';

            return mb_substr(
                $options['upload_dir'],
                mb_strlen(
                    $this->projectDir
                        .\DIRECTORY_SEPARATOR
                        .$public_path
                )
            );
        };

        $allowAdd = static function (Options $options) {
            return $options['multiple'];
        };

        $dataClass = static function (Options $options) {
            return $options['multiple'] ? null : File::class;
        };

        $emptyData = static function (Options $options) {
            return $options['multiple'] ? [] : null;
        };

        $resolver->setDefaults([
            'upload_dir' => $this->projectDir.'/public/uploads/files/',
            'upload_new' => $uploadNew,
            'upload_delete' => $uploadDelete,
            'upload_filename' => $uploadFilename,
            'upload_validate' => $uploadValidate,
            'download_path' => $downloadPath,
            'allow_add' => $allowAdd,
            'allow_delete' => true,
            'data_class' => $dataClass,
            'empty_data' => $emptyData,
            'multiple' => false,
            'required' => false,
            'error_bubbling' => false,
            'allow_file_upload' => true,
        ]);

        $resolver->setAllowedTypes('upload_dir', 'string');
        $resolver->setAllowedTypes('upload_new', 'callable');
        $resolver->setAllowedTypes('upload_delete', 'callable');
        $resolver->setAllowedTypes('upload_filename', ['string', 'callable']);
        $resolver->setAllowedTypes('upload_validate', 'callable');
        $resolver->setAllowedTypes('download_path', ['null', 'string']);
        $resolver->setAllowedTypes('allow_add', 'bool');
        $resolver->setAllowedTypes('allow_delete', 'bool');

        $resolver->setNormalizer('upload_dir', function (Options $options, string $value): string {
            if ('\\' === \DIRECTORY_SEPARATOR) {
                $value = str_replace('\\', '/', $value);
            }

            if ('/' !== mb_substr($value, -1)) {
                $value .= '/';
            }

            if (0 !== mb_strpos($value, '/')) {
                $value = $this->projectDir.'/'.$value;
            }

            if ('' !== $value && (!is_dir($value) || !is_writable($value))) {
                throw new InvalidArgumentException(sprintf('Invalid upload directory "%s" it does not exist or is not writable.', $value));
            }

            return $value;
        });
        $resolver->setNormalizer('upload_filename', static function (Options $options, $value) {
            if (\is_callable($value)) {
                return $value;
            }

            $generateUuid4 = static function () {
                return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                    random_int(0, 0xffff), random_int(0, 0xffff),
                    random_int(0, 0xffff),
                    random_int(0, 0x0fff) | 0x4000,
                    random_int(0, 0x3fff) | 0x8000,
                    random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
                );
            };

            return static function (UploadedFile $file) use ($value, $generateUuid4) {
                return strtr($value, [
                    '[contenthash]' => sha1_file($file->getRealPath()),
                    '[day]' => date('d'),
                    '[extension]' => $file->guessClientExtension(),
                    '[month]' => date('m'),
                    '[name]' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                    '[randomhash]' => bin2hex(random_bytes(20)),
                    '[slug]' => transliterator_transliterate('Any-Latin; Latin-ASCII; [^A-Za-z0-9_] remove; Lower()', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)),
                    '[timestamp]' => time(),
                    '[uuid]' => $generateUuid4(),
                    '[year]' => date('Y'),
                ]);
            };
        });
        $resolver->setNormalizer('allow_add', static function (Options $options, string $value): bool {
            if ($value && !$options['multiple']) {
                throw new InvalidArgumentException('Setting "allow_add" option to "true" when "multiple" option is "false" is not supported.');
            }

            return $value;
        });
    }
}
